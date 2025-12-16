(function () {
    function populateServices(providerId) {
        var serviceSelect = document.getElementById('ms-service');
        if (!serviceSelect || typeof msBookingData === 'undefined') {
            return;
        }

        serviceSelect.innerHTML = '';

        var option = document.createElement('option');
        option.value = '';
        option.textContent = window.wp && wp.i18n ? wp.i18n.__('Select a provider first', 'clinic-manager') : 'Select a provider first';
        serviceSelect.appendChild(option);

        var services = msBookingData.services[providerId] || [];
        services.forEach(function (service) {
            var opt = document.createElement('option');
            opt.value = service.id;
            opt.textContent = service.title;
            serviceSelect.appendChild(opt);
        });
    }

    function handleSubmit(event) {
        event.preventDefault();

        var form = event.target;
        var response = form.querySelector('.ms-response');
        var submitBtn = form.querySelector('button[type="submit"]');
        var endpoint = form.getAttribute('data-endpoint');

        if (!endpoint) {
            return;
        }

        submitBtn.disabled = true;
        response.textContent = '';

        var payload = {
            patient_name: form.patient_name.value,
            phone: form.phone.value,
            provider_id: parseInt(form.provider_id.value, 10),
            service_id: parseInt(form.service_id.value, 10),
            slot_time: form.slot_time.value,
        };

        var otpCode = form.otp_code && form.otp_code.value;
        var otpChallenge = form.otp_challenge_id && form.otp_challenge_id.value;

        if (otpCode && otpChallenge) {
            payload.otp_code = otpCode;
            payload.otp_challenge_id = parseInt(otpChallenge, 10);
        }

        var headers = {
            'Content-Type': 'application/json',
        };

        if (typeof msBookingData !== 'undefined' && msBookingData.nonce) {
            headers['X-WP-Nonce'] = msBookingData.nonce;
        }

        fetch(endpoint, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(payload),
        })
            .then(function (res) {
                if (!res.ok) {
                    return res.json().then(function (data) {
                        throw new Error(data.message || 'Unable to book the appointment');
                    });
                }
                return res.json();
            })
            .then(function (data) {
                response.textContent = (window.wp && wp.i18n ? wp.i18n.__('Appointment booked!', 'clinic-manager') : 'Appointment booked!') + ' #' + data.id;
                form.reset();
                populateServices('');
            })
            .catch(function (err) {
                response.textContent = err.message;
            })
            .finally(function () {
                submitBtn.disabled = false;
            });
    }

    function sendOtp(event) {
        event.preventDefault();

        if (typeof msBookingData === 'undefined' || !msBookingData.otpEndpoint) {
            return;
        }

        var form = document.querySelector('.ms-booking-form');
        var otpButton = document.getElementById('ms-otp-button');
        var otpStatus = document.querySelector('.ms-otp-status');
        var challengeInput = document.getElementById('ms-otp-challenge');

        if (!form || !otpButton || !otpStatus) {
            return;
        }

        var phone = form.phone.value;
        var providerId = parseInt(form.provider_id.value || '0', 10);

        if (!phone) {
            otpStatus.textContent = window.wp && wp.i18n ? wp.i18n.__('Phone is required for OTP', 'clinic-manager') : 'Phone is required for OTP';
            return;
        }

        var headers = {
            'Content-Type': 'application/json',
        };

        if (msBookingData.nonce) {
            headers['X-WP-Nonce'] = msBookingData.nonce;
        }

        otpButton.disabled = true;
        otpStatus.textContent = window.wp && wp.i18n ? wp.i18n.__('Sending code…', 'clinic-manager') : 'Sending code…';

        fetch(msBookingData.otpEndpoint, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({
                phone: phone,
                provider_id: providerId,
                context: 'booking',
            }),
        })
            .then(function (res) {
                if (!res.ok) {
                    return res.json().then(function (data) {
                        throw new Error(data.message || 'Unable to send OTP');
                    });
                }
                return res.json();
            })
            .then(function (data) {
                if (challengeInput) {
                    challengeInput.value = data.challenge_id;
                }
                otpStatus.textContent = window.wp && wp.i18n ? wp.i18n.__('Code sent. Please check your messages.', 'clinic-manager') : 'Code sent. Please check your messages.';
            })
            .catch(function (err) {
                otpStatus.textContent = err.message;
            })
            .finally(function () {
                otpButton.disabled = false;
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var providerSelect = document.getElementById('ms-provider');
        var form = document.querySelector('.ms-booking-form');
        var otpButton = document.getElementById('ms-otp-button');

        if (providerSelect) {
            providerSelect.addEventListener('change', function (event) {
                populateServices(event.target.value);
            });
        }

        if (form) {
            form.addEventListener('submit', handleSubmit);
        }

        if (otpButton) {
            otpButton.addEventListener('click', sendOtp);
        }
    });
})();
