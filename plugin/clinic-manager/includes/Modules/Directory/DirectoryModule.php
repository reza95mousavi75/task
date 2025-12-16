<?php

namespace MS\Modules\Directory;

use MS\Core\Capabilities;
use MS\Core\ModuleInterface;
use MS\Core\ServiceContainer;

class DirectoryModule implements ModuleInterface
{
    public function slug()
    {
        return 'directory';
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
        flush_rewrite_rules(false);
    }

    public function deactivate(ServiceContainer $container)
    {
        flush_rewrite_rules(false);
    }

    public function registerPostTypes()
    {
        register_post_type('ms_provider', [
            'label'        => __('Providers', 'clinic-manager'),
            'public'       => false,
            'show_ui'      => true,
            'supports'     => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'show_in_rest' => true,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'menu_icon'    => 'dashicons-id',
        ]);

        register_post_type('ms_service', [
            'label'        => __('Services', 'clinic-manager'),
            'public'       => false,
            'show_ui'      => true,
            'supports'     => ['title', 'editor', 'custom-fields'],
            'show_in_rest' => true,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'menu_icon'    => 'dashicons-heart',
        ]);
    }

    public function registerRoutes()
    {
        register_rest_route('ms/v1', '/providers', [
            'methods'             => 'GET',
            'callback'            => [$this, 'listProviders'],
            'permission_callback' => '__return_true',
            'args'                => [
                'search'     => ['sanitize_callback' => 'sanitize_text_field'],
                'specialty'  => ['sanitize_callback' => 'sanitize_text_field'],
                'per_page'   => ['sanitize_callback' => 'absint'],
                'page'       => ['sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route('ms/v1', '/providers/(?P<id>\\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getProvider'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('ms/v1', '/providers', [
            'methods'             => 'POST',
            'callback'            => [$this, 'createProvider'],
            'permission_callback' => function () {
                return current_user_can(Capabilities::MANAGE_DIRECTORY);
            },
            'args'                => [
                'name'        => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'specialties' => ['sanitize_callback' => [$this, 'sanitizeList']],
                'bio'         => ['sanitize_callback' => 'wp_kses_post'],
                'rating'      => ['sanitize_callback' => 'floatval'],
            ],
        ]);

        register_rest_route('ms/v1', '/services', [
            'methods'             => 'POST',
            'callback'            => [$this, 'createService'],
            'permission_callback' => function () {
                return current_user_can(Capabilities::MANAGE_DIRECTORY);
            },
            'args'                => [
                'provider_id' => ['required' => true, 'sanitize_callback' => 'absint'],
                'title'       => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'duration'    => ['sanitize_callback' => 'absint'],
                'price'       => ['sanitize_callback' => 'floatval'],
                'description' => ['sanitize_callback' => 'wp_kses_post'],
            ],
        ]);

        register_rest_route('ms/v1', '/providers/(?P<id>\\d+)/services', [
            'methods'             => 'GET',
            'callback'            => [$this, 'listServices'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function listProviders($request)
    {
        $search     = sanitize_text_field($request['search'] ?? '');
        $specialty  = sanitize_text_field($request['specialty'] ?? '');
        $perPage    = absint($request['per_page'] ?? 20);
        $page       = absint($request['page'] ?? 1);

        $metaQuery = [];
        if ($specialty) {
            $metaQuery[] = [
                'key'     => 'ms_specialties',
                'value'   => $specialty,
                'compare' => 'LIKE',
            ];
        }

        $query = new \WP_Query([
            'post_type'      => 'ms_provider',
            'post_status'    => ['publish'],
            'posts_per_page' => $perPage > 0 ? min($perPage, 50) : 20,
            'paged'          => max(1, $page),
            's'              => $search,
            'meta_query'     => $metaQuery,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $data = array_map([$this, 'formatProvider'], $query->posts);

        return [
            'data'  => $data,
            'total' => (int) $query->found_posts,
            'page'  => max(1, $page),
        ];
    }

    public function getProvider($request)
    {
        $id   = absint($request['id'] ?? 0);
        $post = get_post($id);

        if (! $post || $post->post_type !== 'ms_provider') {
            return new \WP_Error('ms_not_found', __('Provider not found', 'clinic-manager'), ['status' => 404]);
        }

        return $this->formatProvider($post, true);
    }

    public function createProvider($request)
    {
        $name        = sanitize_text_field($request['name'] ?? '');
        $bio         = wp_kses_post($request['bio'] ?? '');
        $specialties = $this->sanitizeList($request['specialties'] ?? '');
        $rating      = floatval($request['rating'] ?? 0);

        $postId = wp_insert_post([
            'post_type'   => 'ms_provider',
            'post_status' => 'publish',
            'post_title'  => $name,
            'post_content'=> $bio,
            'meta_input'  => [
                'ms_specialties' => $specialties,
                'ms_rating'      => $rating,
            ],
        ]);

        if (is_wp_error($postId)) {
            return $postId;
        }

        return $this->formatProvider(get_post($postId), true);
    }

    public function createService($request)
    {
        $providerId = absint($request['provider_id'] ?? 0);
        $title      = sanitize_text_field($request['title'] ?? '');
        $duration   = absint($request['duration'] ?? 0);
        $price      = floatval($request['price'] ?? 0);
        $desc       = wp_kses_post($request['description'] ?? '');

        if ($providerId <= 0 || ! get_post($providerId)) {
            return new \WP_Error('ms_invalid_provider', __('Provider is required', 'clinic-manager'), ['status' => 400]);
        }

        $postId = wp_insert_post([
            'post_type'   => 'ms_service',
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_content'=> $desc,
            'meta_input'  => [
                'ms_provider_id' => $providerId,
                'ms_duration'    => $duration,
                'ms_price'       => $price,
            ],
        ]);

        if (is_wp_error($postId)) {
            return $postId;
        }

        return $this->formatService(get_post($postId));
    }

    public function listServices($request)
    {
        $providerId = absint($request['id'] ?? 0);

        $query = new \WP_Query([
            'post_type'      => 'ms_service',
            'post_status'    => ['publish'],
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => 'ms_provider_id',
                    'value'   => $providerId,
                    'compare' => '=',
                ],
            ],
            'orderby'        => 'date',
            'order'          => 'ASC',
        ]);

        $data = array_map([$this, 'formatService'], $query->posts);

        return ['data' => $data];
    }

    public function sanitizeList($value)
    {
        if (is_array($value)) {
            return array_map('sanitize_text_field', $value);
        }

        $parts = array_filter(array_map('trim', explode(',', (string) $value)));

        return array_map('sanitize_text_field', $parts);
    }

    private function formatProvider($post, $includeServices = false)
    {
        $data = [
            'id'          => $post->ID,
            'name'        => $post->post_title,
            'bio'         => $post->post_content,
            'specialties' => get_post_meta($post->ID, 'ms_specialties', true) ?: [],
            'rating'      => (float) get_post_meta($post->ID, 'ms_rating', true),
        ];

        if ($includeServices) {
            $data['services'] = $this->listServices(['id' => $post->ID])['data'];
        }

        return $data;
    }

    private function formatService($post)
    {
        return [
            'id'          => $post->ID,
            'title'       => $post->post_title,
            'description' => $post->post_content,
            'provider_id' => (int) get_post_meta($post->ID, 'ms_provider_id', true),
            'duration'    => (int) get_post_meta($post->ID, 'ms_duration', true),
            'price'       => (float) get_post_meta($post->ID, 'ms_price', true),
        ];
    }
}
