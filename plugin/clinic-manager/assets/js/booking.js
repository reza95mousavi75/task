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

    function formatSlot(dateString) {
        try {
            var date = new Date(dateString);
            if (isNaN(date.getTime())) {
                return '';
            }

            return date.toLocaleString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        } catch (e) {
            return '';
        }
    }

    function updateBusySlots(providerId, slotValue) {
        var busyContainer = document.getElementById('ms-busy-info');

        if (!busyContainer) {
            return;
        }

        if (typeof msBookingData === 'undefined' || !msBookingData.availabilityEndpoint) {
            busyContainer.textContent = '';
            return;
        }

        if (!providerId || !slotValue) {
            busyContainer.textContent = '';
            return;
        }

        var selectedDate = new Date(slotValue);
        if (isNaN(selectedDate.getTime())) {
            busyContainer.textContent = '';
            return;
        }

        var start = new Date(selectedDate);
        start.setHours(0, 0, 0, 0);

        var end = new Date(selectedDate);
        end.setHours(23, 59, 59, 999);

        var url = new URL(msBookingData.availabilityEndpoint);
        url.searchParams.set('provider_id', providerId);
        url.searchParams.set('start', start.toISOString());
        url.searchParams.set('end', end.toISOString());

        busyContainer.textContent = window.wp && wp.i18n ? wp.i18n.__('Checking availability…', 'clinic-manager') : 'Checking availability…';

        fetch(url.toString())
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('Unable to load availability');
                }
                return res.json();
            })
            .then(function (data) {
                if (!Array.isArray(data) || data.length === 0) {
                    busyContainer.textContent = window.wp && wp.i18n ? wp.i18n.__('No conflicts for the selected day.', 'clinic-manager') : 'No conflicts for the selected day.';
                    return;
                }

                var list = data
                    .map(function (slot) {
                        return formatSlot(slot.start) + ' - ' + formatSlot(slot.end);
                    })
                    .filter(function (text) {
                        return Boolean(text.trim());
                    });

                if (!list.length) {
                    busyContainer.textContent = '';
                    return;
                }

                busyContainer.textContent = (window.wp && wp.i18n ? wp.i18n.__('Busy times:', 'clinic-manager') : 'Busy times:') + ' ' + list.join(' | ');
            })
            .catch(function () {
                busyContainer.textContent = window.wp && wp.i18n ? wp.i18n.__('Unable to fetch availability right now.', 'clinic-manager') : 'Unable to fetch availability right now.';
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
        var slotInput = document.getElementById('ms-slot');

        if (providerSelect) {
            providerSelect.addEventListener('change', function (event) {
                var providerId = event.target.value;
                populateServices(providerId);

                if (slotInput) {
                    updateBusySlots(providerId, slotInput.value);
                }
            });
        }

        if (slotInput) {
            slotInput.addEventListener('change', function (event) {
                if (providerSelect) {
                    updateBusySlots(providerSelect.value, event.target.value);
                }
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
