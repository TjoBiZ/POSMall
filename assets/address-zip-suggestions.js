(function () {
    'use strict';

    var debounceMs = 350;
    var googleScriptPromise = null;
    var googlePlacesUnavailable = false;
    var usStateNames = {
        AL: 'ALABAMA',
        AK: 'ALASKA',
        AZ: 'ARIZONA',
        AR: 'ARKANSAS',
        CA: 'CALIFORNIA',
        CO: 'COLORADO',
        CT: 'CONNECTICUT',
        DE: 'DELAWARE',
        DC: 'DISTRICT OF COLUMBIA',
        FL: 'FLORIDA',
        GA: 'GEORGIA',
        HI: 'HAWAII',
        ID: 'IDAHO',
        IL: 'ILLINOIS',
        IN: 'INDIANA',
        IA: 'IOWA',
        KS: 'KANSAS',
        KY: 'KENTUCKY',
        LA: 'LOUISIANA',
        ME: 'MAINE',
        MD: 'MARYLAND',
        MA: 'MASSACHUSETTS',
        MI: 'MICHIGAN',
        MN: 'MINNESOTA',
        MS: 'MISSISSIPPI',
        MO: 'MISSOURI',
        MT: 'MONTANA',
        NE: 'NEBRASKA',
        NV: 'NEVADA',
        NH: 'NEW HAMPSHIRE',
        NJ: 'NEW JERSEY',
        NM: 'NEW MEXICO',
        NY: 'NEW YORK',
        NC: 'NORTH CAROLINA',
        ND: 'NORTH DAKOTA',
        OH: 'OHIO',
        OK: 'OKLAHOMA',
        OR: 'OREGON',
        PA: 'PENNSYLVANIA',
        RI: 'RHODE ISLAND',
        SC: 'SOUTH CAROLINA',
        SD: 'SOUTH DAKOTA',
        TN: 'TENNESSEE',
        TX: 'TEXAS',
        UT: 'UTAH',
        VT: 'VERMONT',
        VA: 'VIRGINIA',
        WA: 'WASHINGTON',
        WV: 'WEST VIRGINIA',
        WI: 'WISCONSIN',
        WY: 'WYOMING'
    };

    function request(element, handler, options) {
        if (!window.oc || typeof window.oc.request !== 'function') {
            return null;
        }

        return window.oc.request(element || document.body, handler, options || {});
    }

    function field(root, prefix, name) {
        return root.querySelector('[data-mall-address-field="' + name + '"]')
            || root.querySelector('[name="' + prefix + name + '"]');
    }

    function value(root, prefix, name) {
        var element = field(root, prefix, name);

        return element ? element.value : '';
    }

    function zip5(value) {
        return String(value || '').replace(/\D+/g, '').slice(0, 5);
    }

    function datalist(root, prefix) {
        var zip = field(root, prefix, 'zip');

        if (!zip || !zip.getAttribute('list')) {
            return null;
        }

        return root.querySelector('#' + zip.getAttribute('list'));
    }

    function addressDatalist(root, prefix) {
        var lines = field(root, prefix, 'lines');

        if (!lines || !lines.getAttribute('list')) {
            return null;
        }

        return root.querySelector('#' + lines.getAttribute('list'));
    }

    function googlePlacesEnabled(root) {
        return root.dataset.mallGooglePlacesEnabled === '1' && !!root.dataset.mallGooglePlacesApiKey;
    }

    function nativeAutocompleteName(name) {
        return {
            lines: 'address-line1',
            details: 'address-line2',
            city: 'address-level2',
            state_id: 'address-level1',
            country_id: 'country',
            zip: 'postal-code'
        }[name] || 'on';
    }

    function managedFieldNames() {
        return ['lines', 'details', 'city', 'state_id', 'country_id', 'zip'];
    }

    function restoreNativeAddressAutocomplete(root, prefix) {
        managedFieldNames().forEach(function (name) {
            var element = field(root, prefix, name);

            if (!element) {
                return;
            }

            element.setAttribute('autocomplete', nativeAutocompleteName(name));
        });

        var nativeLines = field(root, prefix, 'lines');

        if (nativeLines && nativeLines.dataset.mallAddressSuggestionList) {
            nativeLines.setAttribute('list', nativeLines.dataset.mallAddressSuggestionList);
        }

        var form = root.closest('form');

        if (!form || form.dataset.mallAddressAutocompleteManaged !== '1') {
            return;
        }

        if (form.dataset.mallAddressAutocompleteOriginal) {
            form.setAttribute('autocomplete', form.dataset.mallAddressAutocompleteOriginal);
        } else {
            form.removeAttribute('autocomplete');
        }
    }

    function disableNativeAddressAutocomplete(root, prefix) {
        if (!googlePlacesEnabled(root) || googlePlacesUnavailable || root.__mallGooglePlacesUnavailable) {
            restoreNativeAddressAutocomplete(root, prefix);
            return;
        }

        var form = root.closest('form');
        var lines = field(root, prefix, 'lines');

        if (form) {
            if (typeof form.dataset.mallAddressAutocompleteOriginal === 'undefined') {
                form.dataset.mallAddressAutocompleteOriginal = form.getAttribute('autocomplete') || '';
            }

            form.dataset.mallAddressAutocompleteManaged = '1';
            form.setAttribute('autocomplete', 'off');
        }

        managedFieldNames().forEach(function (name) {
            var element = field(root, prefix, name);

            if (!element) {
                return;
            }

            element.setAttribute('autocomplete', 'new-password');
            element.setAttribute('data-lpignore', 'true');
            element.setAttribute('data-1p-ignore', 'true');
            element.setAttribute('autocorrect', 'off');
            element.setAttribute('spellcheck', 'false');
        });

        if (lines) {
            lines.dataset.mallAddressSuggestionList = lines.getAttribute('list') || '';
            lines.removeAttribute('list');
        }
    }

    function markGooglePlacesUnavailable(root, prefix) {
        googlePlacesUnavailable = true;
        root.__mallGooglePlacesUnavailable = true;

        document.querySelectorAll('[data-mall-address-zip-suggest]').forEach(function (candidate) {
            var candidatePrefix = candidate.dataset.mallAddressZipPrefix || '';

            candidate.__mallGooglePlacesUnavailable = true;
            hideAddressPredictions(candidate, candidatePrefix);
            restoreNativeAddressAutocomplete(candidate, candidatePrefix);
        });
    }

    function loadGooglePlaces(apiKey) {
        if (window.google && window.google.maps && typeof window.google.maps.importLibrary === 'function') {
            return Promise.resolve(window.google);
        }

        if (googleScriptPromise) {
            return googleScriptPromise;
        }

        googleScriptPromise = new Promise(function (resolve, reject) {
            var callbackName = '__mallGooglePlacesLoaded' + String(Date.now());
            var script = document.createElement('script');

            window[callbackName] = function () {
                delete window[callbackName];
                resolve(window.google);
            };

            script.src = 'https://maps.googleapis.com/maps/api/js?key='
                + encodeURIComponent(apiKey)
                + '&v=weekly&libraries=places&loading=async&callback='
                + encodeURIComponent(callbackName);
            script.async = true;
            script.defer = true;
            script.onerror = function () {
                delete window[callbackName];
                googleScriptPromise = null;
                reject(new Error('Google Places could not be loaded.'));
            };

            document.head.appendChild(script);
        });

        return googleScriptPromise;
    }

    function predictionText(prediction) {
        if (!prediction) {
            return '';
        }

        if (prediction.text && typeof prediction.text.toString === 'function') {
            return prediction.text.toString();
        }

        return prediction.description || prediction.formattedAddress || '';
    }

    function addressPredictionList(root, prefix) {
        var lines = field(root, prefix, 'lines');

        if (!lines) {
            return null;
        }

        var list = root.querySelector('[data-mall-google-address-predictions]');

        if (list) {
            return list;
        }

        list = document.createElement('ul');
        list.setAttribute('role', 'listbox');
        list.setAttribute('data-mall-google-address-predictions', '1');
        list.className = 'mall-address-autocomplete list-group shadow-sm';
        list.hidden = true;
        list.style.position = 'absolute';
        list.style.left = '0';
        list.style.right = '0';
        list.style.top = '100%';
        list.style.zIndex = '1050';
        list.style.maxHeight = '240px';
        list.style.overflowY = 'auto';
        list.style.padding = '0';
        list.style.margin = '4px 0 0';
        list.style.listStyle = 'none';
        list.style.background = '#fff';

        lines.parentNode.style.position = lines.parentNode.style.position || 'relative';
        lines.parentNode.appendChild(list);

        return list;
    }

    function hideAddressPredictions(root, prefix) {
        var list = addressPredictionList(root, prefix);

        if (!list) {
            return;
        }

        list.hidden = true;
        list.innerHTML = '';

        if (root.__mallGoogleAddressState) {
            root.__mallGoogleAddressState.googleActiveIndex = -1;
        }
    }

    function predictionButtons(root, prefix) {
        var list = addressPredictionList(root, prefix);

        if (!list || list.hidden) {
            return [];
        }

        return Array.prototype.slice.call(list.querySelectorAll('[data-mall-google-prediction-index]'));
    }

    function setActivePrediction(root, prefix, state, index) {
        var buttons = predictionButtons(root, prefix);

        if (!buttons.length) {
            state.googleActiveIndex = -1;
            return;
        }

        if (index < 0) {
            index = buttons.length - 1;
        }

        if (index >= buttons.length) {
            index = 0;
        }

        state.googleActiveIndex = index;

        buttons.forEach(function (button, buttonIndex) {
            var active = buttonIndex === index;

            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.style.background = active ? '#e9f3ff' : '';
        });

        buttons[index].scrollIntoView({block: 'nearest'});
    }

    function applyActivePrediction(root, prefix, state) {
        var buttons = predictionButtons(root, prefix);

        if (!buttons.length) {
            return false;
        }

        if (state.googleActiveIndex < 0) {
            setActivePrediction(root, prefix, state, 0);
        }

        applyGooglePrediction(root, prefix, state, state.googleActiveIndex);

        return true;
    }

    function renderAddressPredictions(root, prefix, state, suggestions) {
        var list = addressPredictionList(root, prefix);

        if (!list) {
            return;
        }

        state.googlePredictions = (suggestions || []).map(function (suggestion) {
            return suggestion.placePrediction || suggestion;
        }).filter(Boolean);

        list.innerHTML = '';

        if (!state.googlePredictions.length) {
            list.hidden = true;
            state.googleActiveIndex = -1;
            return;
        }

        state.googlePredictions.forEach(function (prediction, index) {
            var item = document.createElement('li');
            var button = document.createElement('button');

            item.setAttribute('role', 'option');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action';
            button.style.width = '100%';
            button.style.textAlign = 'left';
            button.textContent = predictionText(prediction);
            button.dataset.mallGooglePredictionIndex = String(index);
            button.setAttribute('aria-selected', 'false');

            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });

            button.addEventListener('click', function () {
                applyGooglePrediction(root, prefix, state, index);
            });

            item.appendChild(button);
            list.appendChild(item);
        });

        list.hidden = false;
        state.googleActiveIndex = -1;
    }

    function legacyAutocompletePredictions(places, input, state) {
        return new Promise(function (resolve, reject) {
            if (!places || typeof places.AutocompleteService !== 'function') {
                reject(new Error('Legacy Google Places autocomplete is unavailable.'));
                return;
            }

            var service = state.legacyAutocompleteService || new places.AutocompleteService();
            state.legacyAutocompleteService = service;

            var request = {
                input: input,
                componentRestrictions: {country: 'us'}
            };

            if (state.googleSessionToken) {
                request.sessionToken = state.googleSessionToken;
            }

            service.getPlacePredictions(request, function (predictions, status) {
                if (status === 'OK' || (places.PlacesServiceStatus && status === places.PlacesServiceStatus.OK)) {
                    resolve({suggestions: predictions || []});
                    return;
                }

                if (status === 'ZERO_RESULTS' || (places.PlacesServiceStatus && status === places.PlacesServiceStatus.ZERO_RESULTS)) {
                    resolve({suggestions: []});
                    return;
                }

                reject(new Error('Legacy Google Places autocomplete failed.'));
            });
        });
    }

    function newAutocompletePredictions(places, input, state) {
        if (!places || !places.AutocompleteSuggestion || typeof places.AutocompleteSuggestion.fetchAutocompleteSuggestions !== 'function') {
            return Promise.reject(new Error('New Google Places autocomplete is unavailable.'));
        }

        return places.AutocompleteSuggestion.fetchAutocompleteSuggestions({
            input: input,
            region: 'us',
            sessionToken: state.googleSessionToken
        });
    }

    function googleAutocompletePredictions(places, input, state) {
        if (!state.googleSessionToken && typeof places.AutocompleteSessionToken === 'function') {
            state.googleSessionToken = new places.AutocompleteSessionToken();
        }

        if (places.AutocompleteSuggestion && typeof places.AutocompleteSuggestion.fetchAutocompleteSuggestions === 'function') {
            return newAutocompletePredictions(places, input, state)
                .catch(function () {
                    return legacyAutocompletePredictions(places, input, state);
                });
        }

        return legacyAutocompletePredictions(places, input, state);
    }

    function componentValue(components, type, shortText) {
        for (var i = 0; i < components.length; i++) {
            var component = components[i];
            var types = component.types || [];

            if (types.indexOf(type) === -1) {
                continue;
            }

            return shortText
                ? (component.shortText || component.short_name || component.longText || component.long_name || '')
                : (component.longText || component.long_name || component.shortText || component.short_name || '');
        }

        return '';
    }

    function parseGoogleAddress(place) {
        var components = place.addressComponents || place.address_components || [];
        var streetNumber = componentValue(components, 'street_number', false);
        var route = componentValue(components, 'route', false);
        var postalCode = componentValue(components, 'postal_code', false);
        var postalSuffix = componentValue(components, 'postal_code_suffix', false);
        var city = componentValue(components, 'locality', false)
            || componentValue(components, 'postal_town', false)
            || componentValue(components, 'sublocality_level_1', false)
            || componentValue(components, 'administrative_area_level_2', false);
        var lines = [streetNumber, route].filter(Boolean).join(' ').trim();

        if (!lines && place.formattedAddress) {
            lines = String(place.formattedAddress).split(',')[0] || '';
        }

        return {
            lines: lines,
            details: componentValue(components, 'subpremise', false),
            city: city,
            state_code: componentValue(components, 'administrative_area_level_1', true),
            country_code: componentValue(components, 'country', true),
            zip: postalSuffix ? postalCode + '-' + postalSuffix : postalCode,
            source: 'google_places'
        };
    }

    function fillGoogleAddress(root, prefix, suggestion) {
        var lines = field(root, prefix, 'lines');
        var details = field(root, prefix, 'details');
        var city = field(root, prefix, 'city');
        var zip = field(root, prefix, 'zip');

        if (lines && suggestion.lines) {
            lines.value = suggestion.lines;
        }

        if (details && suggestion.details) {
            details.value = suggestion.details;
        }

        if (city && suggestion.city) {
            city.value = suggestion.city;
            city.dispatchEvent(new Event('input', {bubbles: true}));
        }

        if (suggestion.country_code) {
            applyCountrySuggestion(root, prefix, suggestion.country_code, suggestion.state_code, null, null);
        }

        if (suggestion.state_code) {
            applyStateSuggestionWithRetry(root, prefix, suggestion.state_code, null, 12);
        }

        if (zip && suggestion.zip) {
            zip.value = suggestion.zip;
            root.__mallGoogleAddressAppliedZip = suggestion.zip;
            zip.dispatchEvent(new Event('input', {bubbles: true}));
        }

        setSuggestions(root, prefix, [suggestion]);
    }

    function applyGooglePrediction(root, prefix, state, index) {
        var prediction = state.googlePredictions[index];

        if (!prediction) {
            return;
        }

        if (typeof prediction.toPlace !== 'function') {
            applyLegacyGooglePrediction(root, prefix, state, prediction);
            return;
        }

        var place = prediction.toPlace();

        Promise.resolve(place.fetchFields({
            fields: ['addressComponents', 'formattedAddress']
        })).then(function () {
            fillGoogleAddress(root, prefix, parseGoogleAddress(place));
            hideAddressPredictions(root, prefix);
            state.googleSessionToken = null;
        }).catch(function () {
            hideAddressPredictions(root, prefix);
        });
    }

    function applyLegacyGooglePrediction(root, prefix, state, prediction) {
        var places = state.placesLibrary;

        if (!prediction.place_id || !places || typeof places.PlacesService !== 'function') {
            hideAddressPredictions(root, prefix);
            return;
        }

        if (!state.legacyPlacesService) {
            state.legacyPlacesService = new places.PlacesService(document.createElement('div'));
        }

        var request = {
            placeId: prediction.place_id,
            fields: ['address_components', 'formatted_address']
        };

        if (state.googleSessionToken) {
            request.sessionToken = state.googleSessionToken;
        }

        state.legacyPlacesService.getDetails(request, function (place, status) {
            if (status === 'OK' || (places.PlacesServiceStatus && status === places.PlacesServiceStatus.OK)) {
                fillGoogleAddress(root, prefix, parseGoogleAddress(place || {}));
            }

            hideAddressPredictions(root, prefix);
            state.googleSessionToken = null;
        });
    }

    function setSuggestions(root, prefix, suggestions) {
        var list = datalist(root, prefix);

        if (!list) {
            return;
        }

        list.innerHTML = '';

        (suggestions || []).forEach(function (suggestion) {
            var option = document.createElement('option');
            option.value = suggestion.zip;

            if (suggestion.label) {
                option.label = suggestion.label;
            }

            option.dataset.mallZipSuggestion = JSON.stringify(suggestion);
            list.appendChild(option);
        });
    }

    function setAddressSuggestions(root, prefix, suggestions) {
        var list = addressDatalist(root, prefix);

        if (!list) {
            return;
        }

        list.innerHTML = '';

        (suggestions || []).filter(function (suggestion) {
            return suggestion.lines;
        }).forEach(function (suggestion) {
            var option = document.createElement('option');
            var label = [suggestion.lines, suggestion.details, suggestion.city, suggestion.state_code, suggestion.zip]
                .filter(Boolean)
                .join(', ');

            option.value = suggestion.lines;
            option.label = label;
            option.dataset.mallAddressSuggestion = JSON.stringify(suggestion);
            list.appendChild(option);
        });
    }

    function stateOptionValue(root, prefix, stateCode, stateId) {
        var state = field(root, prefix, 'state_id');

        if (!state) {
            return '';
        }

        if (stateId && state.querySelector('option[value="' + String(stateId).replace(/"/g, '\\"') + '"]')) {
            return String(stateId);
        }

        if (!stateCode) {
            return '';
        }

        stateCode = String(stateCode).toUpperCase();

        for (var i = 0; i < state.options.length; i++) {
            var option = state.options[i];
            var text = (option.textContent || '').toUpperCase();

            if (
                text === stateCode
                || text === usStateNames[stateCode]
                || text.indexOf('(' + stateCode + ')') !== -1
                || new RegExp('(^|\\b)' + stateCode + '(\\b|$)').test(text)
            ) {
                return option.value;
            }
        }

        return '';
    }

    function countryOptionValue(root, prefix, countryCode, countryId) {
        var country = field(root, prefix, 'country_id');

        if (!country) {
            return '';
        }

        if (countryId && country.querySelector('option[value="' + String(countryId).replace(/"/g, '\\"') + '"]')) {
            return String(countryId);
        }

        if (!countryCode) {
            return '';
        }

        countryCode = String(countryCode).toUpperCase();

        for (var i = 0; i < country.options.length; i++) {
            var option = country.options[i];
            var text = (option.textContent || '').toUpperCase();

            if (
                text === countryCode
                || text.indexOf('(' + countryCode + ')') !== -1
                || (countryCode === 'US' && (text === 'UNITED STATES' || text === 'UNITED STATES OF AMERICA'))
            ) {
                return option.value;
            }
        }

        return '';
    }

    function applyStateSuggestion(root, prefix, stateCode, stateId) {
        var state = field(root, prefix, 'state_id');

        if (!state || (!stateCode && !stateId)) {
            return false;
        }

        if (state.tagName !== 'SELECT') {
            state.value = stateCode || stateId || '';
            state.dispatchEvent(new Event('input', {bubbles: true}));
            state.dispatchEvent(new Event('change', {bubbles: true}));

            return true;
        }

        var stateValue = stateOptionValue(root, prefix, stateCode, stateId);

        if (!stateValue) {
            return false;
        }

        if (state.value === stateValue) {
            return true;
        }

        state.value = stateValue;
        state.dispatchEvent(new Event('change', {bubbles: true}));

        return true;
    }

    function applyStateSuggestionWithRetry(root, prefix, stateCode, stateId, attempts) {
        if (applyStateSuggestion(root, prefix, stateCode, stateId) || attempts < 1) {
            return;
        }

        window.setTimeout(function () {
            root.__mallSuppressZipSuggestionScheduleUntil = Date.now() + 500;
            applyStateSuggestionWithRetry(root, prefix, stateCode, stateId, attempts - 1);
        }, 250);
    }

    function applyCitySuggestion(root, prefix, cityName) {
        var city = field(root, prefix, 'city');

        if (!city || !cityName) {
            return;
        }

        if (city.value === cityName) {
            return;
        }

        city.value = cityName;
    }

    function applyCountrySuggestion(root, prefix, countryCode, stateCode, countryId, stateId) {
        var country = field(root, prefix, 'country_id');

        if (!country || (!countryCode && !countryId)) {
            return;
        }

        if (country.tagName !== 'SELECT') {
            country.value = countryCode || countryId || '';
            country.dispatchEvent(new Event('input', {bubbles: true}));
            country.dispatchEvent(new Event('change', {bubbles: true}));

            if (stateCode) {
                window.setTimeout(function () {
                    applyStateSuggestionWithRetry(root, prefix, stateCode, stateId, 12);
                }, 250);
            }

            return;
        }

        var countryValue = countryOptionValue(root, prefix, countryCode, countryId);

        if (!countryValue || country.value === countryValue) {
            return;
        }

        country.value = countryValue;
        country.dispatchEvent(new Event('change', {bubbles: true}));

        if (stateCode) {
            window.setTimeout(function () {
                applyStateSuggestionWithRetry(root, prefix, stateCode, stateId, 12);
            }, 250);
        }
    }

    function applyZipSuggestion(root, prefix) {
        var zip = field(root, prefix, 'zip');
        var list = datalist(root, prefix);

        if (!zip || !list) {
            return false;
        }

        var selected = Array.prototype.slice.call(list.querySelectorAll('option')).find(function (option) {
            return option.value === zip.value && option.dataset.mallZipSuggestion;
        });

        if (!selected) {
            return false;
        }

        var suggestion = JSON.parse(selected.dataset.mallZipSuggestion);

        root.__mallSuppressZipSuggestionScheduleUntil = Date.now() + 3500;
        applyCountrySuggestion(root, prefix, suggestion.country_code, suggestion.state_code, suggestion.country_id, suggestion.state_id);
        applyStateSuggestionWithRetry(root, prefix, suggestion.state_code, suggestion.state_id, 12);
        applyCitySuggestion(root, prefix, suggestion.city);

        return true;
    }

    function applyExactZipSuggestion(root, prefix, suggestions) {
        var zip = field(root, prefix, 'zip');
        var normalizedZip = zip ? zip5(zip.value) : '';

        if (normalizedZip.length !== 5) {
            return false;
        }

        var suggestion = (suggestions || []).find(function (candidate) {
            return candidate.zip && String(candidate.zip).replace(/\D+/g, '').slice(0, 5) === normalizedZip;
        });

        if (!suggestion) {
            return false;
        }

        root.__mallSuppressZipSuggestionScheduleUntil = Date.now() + 3500;
        applyCountrySuggestion(root, prefix, suggestion.country_code, suggestion.state_code, suggestion.country_id, suggestion.state_id);
        applyStateSuggestionWithRetry(root, prefix, suggestion.state_code, suggestion.state_id, 12);

        if (
            suggestion.source === 'usps'
            || !root.__mallGoogleAddressAppliedZip
            || zip5(root.__mallGoogleAddressAppliedZip) !== normalizedZip
        ) {
            applyCitySuggestion(root, prefix, suggestion.city);
        }

        return true;
    }

    function applyAddressSuggestion(root, prefix) {
        var lines = field(root, prefix, 'lines');
        var list = addressDatalist(root, prefix);

        if (!lines || !list) {
            return false;
        }

        var selected = Array.prototype.slice.call(list.querySelectorAll('option')).find(function (option) {
            return option.value === lines.value && option.dataset.mallAddressSuggestion;
        });

        if (!selected) {
            return false;
        }

        var suggestion = JSON.parse(selected.dataset.mallAddressSuggestion);
        var details = field(root, prefix, 'details');
        var city = field(root, prefix, 'city');
        var state = field(root, prefix, 'state_id');
        var zip = field(root, prefix, 'zip');

        lines.value = suggestion.lines || lines.value;

        if (details && suggestion.details) {
            details.value = suggestion.details;
        }

        if (city && suggestion.city) {
            city.value = suggestion.city;
        }

        if (suggestion.country_code) {
            applyCountrySuggestion(root, prefix, suggestion.country_code, suggestion.state_code, suggestion.country_id, suggestion.state_id);
        }

        if (state && suggestion.state_code) {
            applyStateSuggestionWithRetry(root, prefix, suggestion.state_code, suggestion.state_id, 12);
        }

        if (zip && suggestion.zip) {
            zip.value = suggestion.zip;
            zip.dispatchEvent(new Event('input', {bubbles: true}));
        }

        setAddressSuggestions(root, prefix, []);
        setSuggestions(root, prefix, [suggestion]);

        return true;
    }

    function bind(root) {
        if (root.dataset.mallAddressZipSuggestReady === '1') {
            return;
        }

        root.dataset.mallAddressZipSuggestReady = '1';

        var handler = root.dataset.mallAddressZipHandler;
        var prefix = root.dataset.mallAddressZipPrefix || '';
        var timer = null;
        var localRequestCounter = 0;
        var googleState = {
            googleActiveIndex: -1,
            googlePredictions: [],
            googleRequestCounter: 0,
            googleSessionToken: null,
            googleTimer: null,
            legacyAutocompleteService: null,
            legacyPlacesService: null,
            placesLibrary: null
        };
        root.__mallGoogleAddressState = googleState;
        disableNativeAddressAutocomplete(root, prefix);

        if (!handler) {
            return;
        }

        function scheduleGoogleAddressPredictions() {
            if (!googlePlacesEnabled(root) || googlePlacesUnavailable || root.__mallGooglePlacesUnavailable) {
                restoreNativeAddressAutocomplete(root, prefix);
                hideAddressPredictions(root, prefix);
                return;
            }

            window.clearTimeout(googleState.googleTimer);
            googleState.googleTimer = window.setTimeout(function () {
                var lines = value(root, prefix, 'lines').trim();

                if (lines.length < 6) {
                    hideAddressPredictions(root, prefix);
                    return;
                }

                var requestId = ++googleState.googleRequestCounter;
                var fallbackTimer = window.setTimeout(function () {
                    if (requestId === googleState.googleRequestCounter) {
                        markGooglePlacesUnavailable(root, prefix);
                    }
                }, 2500);

                loadGooglePlaces(root.dataset.mallGooglePlacesApiKey)
                    .then(function (google) {
                        return googleState.placesLibrary
                            ? googleState.placesLibrary
                            : google.maps.importLibrary('places');
                    })
                    .then(function (places) {
                        googleState.placesLibrary = places;
                        return googleAutocompletePredictions(places, lines, googleState);
                    })
                    .then(function (response) {
                        window.clearTimeout(fallbackTimer);

                        if (requestId !== googleState.googleRequestCounter) {
                            return;
                        }

                        renderAddressPredictions(root, prefix, googleState, (response && response.suggestions) || []);
                    })
                    .catch(function () {
                        window.clearTimeout(fallbackTimer);

                        if (requestId === googleState.googleRequestCounter) {
                            markGooglePlacesUnavailable(root, prefix);
                        }
                    });
            }, debounceMs);
        }

        function schedule() {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                var zip = zip5(value(root, prefix, 'zip'));
                var lines = value(root, prefix, 'lines');
                var city = value(root, prefix, 'city');

                if (!zip && lines.trim().length < 2 && city.trim().length < 2) {
                    setSuggestions(root, prefix, []);
                    return;
                }

                var localRequestId = ++localRequestCounter;

                request(root, handler, {
                    data: {
                        country_id: value(root, prefix, 'country_id'),
                        state_id: value(root, prefix, 'state_id'),
                        country_code: value(root, prefix, 'country_id'),
                        state_code: value(root, prefix, 'state_id'),
                        lines: lines,
                        city: city,
                        zip: zip
                    },
                    success: function (data) {
                        if (localRequestId !== localRequestCounter) {
                            return;
                        }

                        var suggestions = (data && data.suggestions) || [];

                        setAddressSuggestions(root, prefix, suggestions);
                        setSuggestions(root, prefix, suggestions);
                        applyExactZipSuggestion(root, prefix, suggestions);
                    }
                });
            }, debounceMs);
        }

        ['lines', 'details', 'city', 'zip', 'country_id', 'state_id'].forEach(function (name) {
            root.addEventListener(name === 'state_id' || name === 'country_id' ? 'change' : 'input', function (event) {
                var target = field(root, prefix, name);
                var matchesMappedField = event.target && target && event.target === target;
                var matchesNativeName = event.target && event.target.name === prefix + name;

                if (matchesMappedField || matchesNativeName) {
                    if (name === 'lines' && applyAddressSuggestion(root, prefix)) {
                        return;
                    }

                    if (name === 'lines') {
                        scheduleGoogleAddressPredictions();
                    }

                    if (name === 'zip') {
                        applyZipSuggestion(root, prefix);
                    }

                    if (
                        (name === 'country_id' || name === 'state_id')
                        && root.__mallSuppressZipSuggestionScheduleUntil
                        && Date.now() < root.__mallSuppressZipSuggestionScheduleUntil
                    ) {
                        return;
                    }

                    schedule();
                }
            });
        });

        var lines = field(root, prefix, 'lines');

        if (lines) {
            lines.addEventListener('keydown', function (event) {
                var buttons = predictionButtons(root, prefix);

                if (!buttons.length) {
                    return;
                }

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    setActivePrediction(root, prefix, googleState, googleState.googleActiveIndex + 1);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    setActivePrediction(root, prefix, googleState, googleState.googleActiveIndex - 1);
                } else if (event.key === 'Enter') {
                    event.preventDefault();
                    applyActivePrediction(root, prefix, googleState);
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    hideAddressPredictions(root, prefix);
                }
            });
        }

        root.addEventListener('focusout', function () {
            window.setTimeout(function () {
                hideAddressPredictions(root, prefix);
            }, 150);
        });

        schedule();
    }

    function init() {
        document.querySelectorAll('[data-mall-address-zip-suggest]').forEach(bind);
    }

    document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('ajax:update', init);
    document.addEventListener('turbo:load', init);
    document.addEventListener('page:loaded', init);
})();
