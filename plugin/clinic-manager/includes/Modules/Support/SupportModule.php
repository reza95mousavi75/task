<?php

namespace MS\Modules\Support;

use MS\Core\Capabilities;
use MS\Core\ModuleInterface;
use MS\Core\ServiceContainer;

class SupportModule implements ModuleInterface
{
    public function slug()
    {
        return 'support';
    }

    public function version()
    {
        return '0.1.0';
    }

    public function boot(ServiceContainer $container)
    {
        add_action('init', [$this, 'registerPostTypes']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function activate(ServiceContainer $container)
    {
        $this->registerPostTypes();
        flush_rewrite_rules();
    }

    public function deactivate(ServiceContainer $container)
    {
        flush_rewrite_rules();
    }

    public function registerPostTypes()
    {
        register_post_type('ms_ticket', [
            'label'        => __('Support Tickets', 'clinic-manager'),
            'public'       => false,
            'show_ui'      => true,
            'supports'     => ['title', 'editor', 'custom-fields'],
            'show_in_rest' => true,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'menu_icon'    => 'dashicons-sos',
        ]);
    }

    public function registerRoutes()
    {
        register_rest_route('ms/v1', '/support/tickets', [
            'methods'             => 'POST',
            'callback'            => [$this, 'createTicket'],
            'permission_callback' => '__return_true',
            'args'                => [
                'subject'    => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'message'    => ['required' => true],
                'patient_id' => ['sanitize_callback' => 'absint'],
                'phone'      => ['sanitize_callback' => 'sanitize_text_field'],
                'email'      => ['sanitize_callback' => 'sanitize_email'],
                'priority'   => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route('ms/v1', '/support/tickets', [
            'methods'             => 'GET',
            'callback'            => [$this, 'listTickets'],
            'permission_callback' => function () {
                return current_user_can(Capabilities::MANAGE_SUPPORT);
            },
            'args'                => [
                'status'    => ['sanitize_callback' => 'sanitize_text_field'],
                'patient_id'=> ['sanitize_callback' => 'absint'],
                'per_page'  => ['sanitize_callback' => 'absint'],
                'page'      => ['sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route('ms/v1', '/support/tickets/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getTicket'],
            'permission_callback' => function () {
                return current_user_can(Capabilities::MANAGE_SUPPORT);
            },
        ]);

        register_rest_route('ms/v1', '/support/tickets/(?P<id>\d+)/messages', [
            'methods'             => 'POST',
            'callback'            => [$this, 'addMessage'],
            'permission_callback' => function () {
                return current_user_can(Capabilities::MANAGE_SUPPORT);
            },
            'args'                => [
                'message' => ['required' => true],
            ],
        ]);

        register_rest_route('ms/v1', '/support/tickets/(?P<id>\d+)/status', [
            'methods'             => 'PATCH',
            'callback'            => [$this, 'updateStatus'],
            'permission_callback' => function () {
                return current_user_can(Capabilities::MANAGE_SUPPORT);
            },
            'args'                => [
                'status' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    public function createTicket($request)
    {
        $subject   = sanitize_text_field($request['subject'] ?? '');
        $message   = wp_kses_post($request['message'] ?? '');
        $patientId = absint($request['patient_id'] ?? 0);
        $phone     = sanitize_text_field($request['phone'] ?? '');
        $email     = sanitize_email($request['email'] ?? '');
        $priority  = sanitize_text_field($request['priority'] ?? 'normal');

        if (! $subject || ! $message) {
            return new \WP_Error('ms_missing_fields', __('Subject and message are required.', 'clinic-manager'), ['status' => 400]);
        }

        $ticketId = wp_insert_post([
            'post_type'   => 'ms_ticket',
            'post_title'  => $subject,
            'post_status' => 'publish',
            'meta_input'  => [
                'ms_patient_id' => $patientId,
                'ms_phone'      => $phone,
                'ms_email'      => $email,
                'ms_priority'   => $priority,
                'ms_status'     => 'open',
                'ms_messages'   => wp_json_encode([
                    [
                        'message'    => $message,
                        'author'     => $this->resolveAuthor(),
                        'created_at' => current_time('mysql'),
                    ],
                ]),
            ],
        ]);

        if (is_wp_error($ticketId)) {
            return $ticketId;
        }

        return $this->formatTicket(get_post($ticketId));
    }

    public function listTickets($request)
    {
        $status    = sanitize_text_field($request['status'] ?? '');
        $patientId = absint($request['patient_id'] ?? 0);
        $perPage   = absint($request['per_page'] ?? 20);
        $page      = absint($request['page'] ?? 1);

        $metaQuery = [];

        if ($status) {
            $metaQuery[] = [
                'key'   => 'ms_status',
                'value' => $status,
            ];
        }

        if ($patientId) {
            $metaQuery[] = [
                'key'   => 'ms_patient_id',
                'value' => $patientId,
            ];
        }

        $queryArgs = [
            'post_type'      => 'ms_ticket',
            'post_status'    => 'publish',
            'posts_per_page' => $perPage,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if ($metaQuery) {
            $queryArgs['meta_query'] = $metaQuery;
        }

        $query   = new \WP_Query($queryArgs);
        $tickets = array_map([$this, 'formatTicket'], $query->posts);

        return [
            'data'       => $tickets,
            'total'      => (int) $query->found_posts,
            'totalPages' => (int) $query->max_num_pages,
            'page'       => $page,
            'per_page'   => $perPage,
        ];
    }

    public function getTicket($request)
    {
        $ticket = get_post(absint($request['id']));

        if (! $ticket || $ticket->post_type !== 'ms_ticket') {
            return new \WP_Error('ms_ticket_not_found', __('Ticket not found.', 'clinic-manager'), ['status' => 404]);
        }

        return $this->formatTicket($ticket);
    }

    public function addMessage($request)
    {
        $ticketId = absint($request['id']);
        $ticket   = get_post($ticketId);

        if (! $ticket || $ticket->post_type !== 'ms_ticket') {
            return new \WP_Error('ms_ticket_not_found', __('Ticket not found.', 'clinic-manager'), ['status' => 404]);
        }

        $message = wp_kses_post($request['message'] ?? '');

        if (! $message) {
            return new \WP_Error('ms_missing_fields', __('Message is required.', 'clinic-manager'), ['status' => 400]);
        }

        $messages = $this->getMessages($ticketId);

        $messages[] = [
            'message'    => $message,
            'author'     => $this->resolveAuthor(),
            'created_at' => current_time('mysql'),
        ];

        update_post_meta($ticketId, 'ms_messages', wp_json_encode($messages));

        return $this->formatTicket($ticket);
    }

    public function updateStatus($request)
    {
        $ticketId = absint($request['id']);
        $ticket   = get_post($ticketId);

        if (! $ticket || $ticket->post_type !== 'ms_ticket') {
            return new \WP_Error('ms_ticket_not_found', __('Ticket not found.', 'clinic-manager'), ['status' => 404]);
        }

        $status       = sanitize_text_field($request['status'] ?? '');
        $allowed      = ['open', 'pending', 'resolved', 'closed'];
        if (! in_array($status, $allowed, true)) {
            return new \WP_Error('ms_invalid_status', __('Invalid status value.', 'clinic-manager'), ['status' => 400]);
        }

        update_post_meta($ticketId, 'ms_status', $status);

        return $this->formatTicket($ticket);
    }

    private function getMessages($ticketId)
    {
        $raw = get_post_meta($ticketId, 'ms_messages', true);

        if (! $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function formatTicket($post)
    {
        $messages = $this->getMessages($post->ID);

        return [
            'id'         => $post->ID,
            'subject'    => $post->post_title,
            'status'     => get_post_meta($post->ID, 'ms_status', true) ?: 'open',
            'priority'   => get_post_meta($post->ID, 'ms_priority', true) ?: 'normal',
            'patient_id' => (int) get_post_meta($post->ID, 'ms_patient_id', true),
            'phone'      => get_post_meta($post->ID, 'ms_phone', true),
            'email'      => get_post_meta($post->ID, 'ms_email', true),
            'messages'   => $messages,
            'created_at' => get_date_from_gmt($post->post_date_gmt, 'Y-m-d H:i:s'),
        ];
    }

    private function resolveAuthor()
    {
        if (is_user_logged_in()) {
            $user = wp_get_current_user();

            return [
                'id'    => $user->ID,
                'name'  => $user->display_name,
                'email' => $user->user_email,
            ];
        }

        return [
            'id'    => 0,
            'name'  => __('Guest', 'clinic-manager'),
            'email' => '',
        ];
    }
}
