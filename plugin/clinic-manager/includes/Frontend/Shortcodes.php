<?php

namespace MS\Frontend;

class Shortcodes
{
    public function hooks()
    {
        add_action('init', [$this, 'registerShortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
    }

    public function registerAssets()
    {
        $baseUrl = plugin_dir_url(dirname(__DIR__, 2) . '/clinic-manager.php');

        wp_register_style(
            'ms-booking',
            $baseUrl . 'assets/css/booking.css',
            [],
            '0.1.0'
        );

        wp_register_script(
            'ms-booking',
            $baseUrl . 'assets/js/booking.js',
            [],
            '0.1.0',
            true
        );
    }

    public function registerShortcodes()
    {
        add_shortcode('ms_booking_form', [$this, 'renderBookingForm']);
        add_shortcode('ms_provider_directory', [$this, 'renderProviderDirectory']);
    }

    public function renderBookingForm($atts)
    {
        $this->ensureAssets();

        $providers = $this->getProviders();
        $services  = $this->getServicesByProvider();

        wp_localize_script('ms-booking', 'msBookingData', [
            'endpoint'  => rest_url('ms/v1/appointments'),
            'otpEndpoint' => rest_url('ms/v1/otp'),
            'nonce'     => wp_create_nonce('wp_rest'),
            'providers' => $providers,
            'services'  => $services,
        ]);

        ob_start();
        ?>
        <div class="ms-booking">
            <form class="ms-booking-form" data-endpoint="<?php echo esc_url(rest_url('ms/v1/appointments')); ?>">
                <div class="ms-field">
                    <label for="ms-patient-name"><?php esc_html_e('Patient name', 'clinic-manager'); ?></label>
                    <input type="text" id="ms-patient-name" name="patient_name" required />
                </div>
                <div class="ms-field">
                    <label for="ms-phone"><?php esc_html_e('Phone', 'clinic-manager'); ?></label>
                    <input type="tel" id="ms-phone" name="phone" required />
                </div>
                <div class="ms-field ms-otp">
                    <label for="ms-otp-code"><?php esc_html_e('Verification code', 'clinic-manager'); ?></label>
                    <div class="ms-otp-row">
                        <input type="text" id="ms-otp-code" name="otp_code" placeholder="<?php esc_attr_e('Enter code (optional)', 'clinic-manager'); ?>" />
                        <button type="button" class="ms-otp-button" id="ms-otp-button"><?php esc_html_e('Send code', 'clinic-manager'); ?></button>
                    </div>
                    <input type="hidden" id="ms-otp-challenge" name="otp_challenge_id" />
                    <div class="ms-otp-status" role="status" aria-live="polite"></div>
                </div>
                <div class="ms-field">
                    <label for="ms-provider"><?php esc_html_e('Provider', 'clinic-manager'); ?></label>
                    <select id="ms-provider" name="provider_id" required>
                        <option value=""><?php esc_html_e('Select', 'clinic-manager'); ?></option>
                        <?php foreach ($providers as $provider) : ?>
                            <option value="<?php echo esc_attr($provider['id']); ?>"><?php echo esc_html($provider['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ms-field">
                    <label for="ms-service"><?php esc_html_e('Service', 'clinic-manager'); ?></label>
                    <select id="ms-service" name="service_id" required>
                        <option value=""><?php esc_html_e('Select a provider first', 'clinic-manager'); ?></option>
                    </select>
                </div>
                <div class="ms-field">
                    <label for="ms-slot"><?php esc_html_e('Appointment time', 'clinic-manager'); ?></label>
                    <input type="datetime-local" id="ms-slot" name="slot_time" required />
                </div>
                <div class="ms-actions">
                    <button type="submit" class="ms-submit"><?php esc_html_e('Book appointment', 'clinic-manager'); ?></button>
                </div>
                <div class="ms-response" role="status" aria-live="polite"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function renderProviderDirectory($atts)
    {
        $this->ensureAssets(false);

        $providers = $this->getProviders();

        ob_start();
        ?>
        <div class="ms-directory">
            <?php foreach ($providers as $provider) : ?>
                <div class="ms-provider-card">
                    <div class="ms-provider-name"><?php echo esc_html($provider['name']); ?></div>
                    <?php if (! empty($provider['specialties'])) : ?>
                        <div class="ms-provider-specialties"><?php echo esc_html(implode(', ', $provider['specialties'])); ?></div>
                    <?php endif; ?>
                    <?php if ($provider['rating'] > 0) : ?>
                        <div class="ms-provider-rating"><?php echo esc_html(number_format((float) $provider['rating'], 1)); ?> ★</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function ensureAssets($includeScript = true)
    {
        wp_enqueue_style('ms-booking');

        if ($includeScript) {
            wp_enqueue_script('ms-booking');
        }
    }

    private function getProviders()
    {
        $query = new \WP_Query([
            'post_type'      => 'ms_provider',
            'post_status'    => ['publish'],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $providers = [];

        foreach ($query->posts as $post) {
            $providers[] = [
                'id'          => $post->ID,
                'name'        => $post->post_title,
                'specialties' => get_post_meta($post->ID, 'ms_specialties', true) ?: [],
                'rating'      => (float) get_post_meta($post->ID, 'ms_rating', true),
            ];
        }

        return $providers;
    }

    private function getServicesByProvider()
    {
        $query = new \WP_Query([
            'post_type'      => 'ms_service',
            'post_status'    => ['publish'],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'ASC',
        ]);

        $services = [];

        foreach ($query->posts as $post) {
            $providerId = (int) get_post_meta($post->ID, 'ms_provider_id', true);
            if (! isset($services[$providerId])) {
                $services[$providerId] = [];
            }

            $services[$providerId][] = [
                'id'    => $post->ID,
                'title' => $post->post_title,
                'price' => (float) get_post_meta($post->ID, 'ms_price', true),
            ];
        }

        return $services;
    }
}
