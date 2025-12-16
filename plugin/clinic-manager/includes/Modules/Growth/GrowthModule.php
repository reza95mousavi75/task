<?php

namespace MS\Modules\Growth;

use MS\Core\Capabilities;
use MS\Core\ModuleInterface;
use MS\Core\ServiceContainer;

class GrowthModule implements ModuleInterface
{
    public function slug()
    {
        return 'growth';
    }

    public function version()
    {
        return '0.1.0';
    }

    public function boot(ServiceContainer $container)
    {
        add_action('init', [$this, 'registerPostTypes']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('ms_appointment_booked', [$this, 'incrementBooking']);
        add_action('ms_appointment_status_changed', [$this, 'incrementBooking']);
    }

    public function activate(ServiceContainer $container)
    {
        $this->registerPostTypes();
        $this->createTables();
    }

    public function deactivate(ServiceContainer $container)
    {
        // Keep data; add cleanup of scheduled events when they exist.
    }

    public function registerPostTypes()
    {
        register_post_type('ms_review', [
            'label'        => __('Reviews', 'clinic-manager'),
            'public'       => false,
            'show_ui'      => true,
            'supports'     => ['title', 'editor', 'custom-fields'],
            'show_in_rest' => true,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'menu_icon'    => 'dashicons-star-half',
        ]);
    }

    public function registerRoutes()
    {
        register_rest_route('ms/v1', '/reviews', [
            'methods'             => 'POST',
            'callback'            => [$this, 'createReview'],
            'permission_callback' => '__return_true',
            'args'                => [
                'provider_id'  => ['required' => true, 'sanitize_callback' => 'absint'],
                'rating'       => ['required' => true, 'sanitize_callback' => 'absint'],
                'title'        => ['sanitize_callback' => 'sanitize_text_field'],
                'comment'      => [],
                'patient_name' => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route('ms/v1', '/reviews', [
            'methods'             => 'GET',
            'callback'            => [$this, 'listReviews'],
            'permission_callback' => function () {
                return current_user_can(Capabilities::MANAGE_GROWTH);
            },
            'args'                => [
                'provider_id' => ['sanitize_callback' => 'absint'],
                'per_page'    => ['sanitize_callback' => 'absint'],
                'page'        => ['sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route('ms/v1', '/profile-views', [
            'methods'             => 'POST',
            'callback'            => [$this, 'recordProfileView'],
            'permission_callback' => '__return_true',
            'args'                => [
                'provider_id' => ['required' => true, 'sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route('ms/v1', '/providers/(?P<provider_id>\\d+)/growth', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getProviderGrowth'],
            'permission_callback' => function () {
                return current_user_can(Capabilities::MANAGE_GROWTH);
            },
        ]);
    }

    public function createReview($request)
    {
        $providerId = absint($request['provider_id'] ?? 0);
        $rating     = absint($request['rating'] ?? 0);
        $title      = sanitize_text_field($request['title'] ?? '');
        $comment    = wp_kses_post($request['comment'] ?? '');
        $patient    = sanitize_text_field($request['patient_name'] ?? '');

        if ($providerId <= 0 || $rating < 1 || $rating > 5) {
            return new \WP_Error('ms_invalid_review', __('Provider and rating are required.', 'clinic-manager'), ['status' => 400]);
        }

        $postId = wp_insert_post([
            'post_type'    => 'ms_review',
            'post_status'  => 'publish',
            'post_title'   => $title ?: sprintf(__('Review for provider %d', 'clinic-manager'), $providerId),
            'post_content' => $comment,
            'meta_input'   => [
                'ms_provider_id'  => $providerId,
                'ms_rating'       => $rating,
                'ms_patient_name' => $patient,
            ],
        ]);

        if (is_wp_error($postId)) {
            return $postId;
        }

        return $this->formatReview(get_post($postId));
    }

    public function listReviews($request)
    {
        $providerId = absint($request['provider_id'] ?? 0);
        $perPage    = absint($request['per_page'] ?? 10);
        $page       = absint($request['page'] ?? 1);

        $metaQuery = [];

        if ($providerId) {
            $metaQuery[] = [
                'key'     => 'ms_provider_id',
                'value'   => $providerId,
                'compare' => '=',
            ];
        }

        $query = new \WP_Query([
            'post_type'      => 'ms_review',
            'post_status'    => ['publish'],
            'posts_per_page' => $perPage > 0 ? min($perPage, 50) : 10,
            'paged'          => $page > 0 ? $page : 1,
            'meta_query'     => $metaQuery,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $data = array_map([$this, 'formatReview'], $query->posts);

        return [
            'data'  => $data,
            'total' => (int) $query->found_posts,
            'page'  => (int) ($page > 0 ? $page : 1),
        ];
    }

    public function recordProfileView($request)
    {
        global $wpdb;
        $providerId = absint($request['provider_id'] ?? 0);

        if ($providerId <= 0) {
            return new \WP_Error('ms_invalid_provider', __('Provider is required', 'clinic-manager'), ['status' => 400]);
        }

        $table = $wpdb->prefix . 'ms_profile_stats';
        $now   = current_time('mysql');

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (provider_id, views, bookings, updated_at) VALUES (%d, 1, 0, %s)
                ON DUPLICATE KEY UPDATE views = views + 1, updated_at = VALUES(updated_at)",
                $providerId,
                $now
            )
        );

        return ['provider_id' => $providerId, 'views' => (int) $this->getStatValue($providerId, 'views')];
    }

    public function incrementBooking($providerId)
    {
        global $wpdb;

        $providerId = absint($providerId);
        if ($providerId <= 0) {
            return;
        }

        $table = $wpdb->prefix . 'ms_profile_stats';
        $now   = current_time('mysql');

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (provider_id, views, bookings, updated_at) VALUES (%d, 0, 1, %s)
                ON DUPLICATE KEY UPDATE bookings = bookings + 1, updated_at = VALUES(updated_at)",
                $providerId,
                $now
            )
        );
    }

    public function getProviderGrowth($request)
    {
        $providerId = absint($request['provider_id'] ?? 0);

        if ($providerId <= 0) {
            return new \WP_Error('ms_invalid_provider', __('Provider is required', 'clinic-manager'), ['status' => 400]);
        }

        $stats   = $this->getStats($providerId);
        $ratings = $this->getRatingSummary($providerId);

        return [
            'provider_id' => $providerId,
            'views'       => (int) ($stats['views'] ?? 0),
            'bookings'    => (int) ($stats['bookings'] ?? 0),
            'avg_rating'  => (float) $ratings['average'],
            'reviews'     => (int) $ratings['count'],
        ];
    }

    private function getStats($providerId)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ms_profile_stats';

        $row = $wpdb->get_row($wpdb->prepare("SELECT views, bookings FROM {$table} WHERE provider_id = %d", $providerId), ARRAY_A);

        return $row ?: ['views' => 0, 'bookings' => 0];
    }

    private function getRatingSummary($providerId)
    {
        $query = new \WP_Query([
            'post_type'      => 'ms_review',
            'post_status'    => ['publish'],
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => 'ms_provider_id',
                    'value'   => $providerId,
                    'compare' => '=',
                ],
            ],
            'fields'         => 'ids',
        ]);

        $total = 0;
        $count = 0;

        foreach ($query->posts as $postId) {
            $rating = absint(get_post_meta($postId, 'ms_rating', true));
            if ($rating > 0) {
                $total += $rating;
                $count++;
            }
        }

        return [
            'average' => $count > 0 ? round($total / $count, 2) : 0,
            'count'   => $count,
        ];
    }

    private function getStatValue($providerId, $column)
    {
        $stats = $this->getStats($providerId);
        return isset($stats[$column]) ? (int) $stats[$column] : 0;
    }

    private function createTables()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table          = $wpdb->prefix . 'ms_profile_stats';
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            provider_id bigint(20) unsigned NOT NULL,
            views bigint(20) unsigned NOT NULL DEFAULT 0,
            bookings bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (provider_id)
        ) {$charsetCollate};";

        dbDelta($sql);
    }

    private function formatReview($post)
    {
        return [
            'id'           => $post->ID,
            'provider_id'  => (int) get_post_meta($post->ID, 'ms_provider_id', true),
            'rating'       => (int) get_post_meta($post->ID, 'ms_rating', true),
            'patient_name' => get_post_meta($post->ID, 'ms_patient_name', true),
            'title'        => $post->post_title,
            'comment'      => $post->post_content,
            'created_at'   => $post->post_date,
        ];
    }
}
