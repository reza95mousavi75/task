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

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
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

    document.addEventListener('DOMContentLoaded', function () {
        var providerSelect = document.getElementById('ms-provider');
        var form = document.querySelector('.ms-booking-form');

        if (providerSelect) {
            providerSelect.addEventListener('change', function (event) {
                populateServices(event.target.value);
            });
        }

        if (form) {
            form.addEventListener('submit', handleSubmit);
        }
    });
})();
