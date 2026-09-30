
(function() {
    'use strict';
    
    // Global category change handler for direct button onclicks
    window.bkChangeCategory = window.bkChangeCategory || function(e, btn, delta) {
        if (e) {
            if (e.preventDefault) e.preventDefault();
            if (e.stopPropagation) e.stopPropagation();
        }
        var row = btn.closest ? btn.closest('.bk-category-row') : null;
        if (!row) return;
        var widget = row.closest ? row.closest('.bokun-booking-widget') : document.querySelector('.bokun-booking-widget');
        var valEl = row.querySelector('.bk-val');
        if (!valEl) return;
        var cur = parseInt(valEl.textContent.trim(), 10) || 0;
        var nextVal = Math.max(0, cur + delta);
        valEl.textContent = nextVal;
        
        var catId = row.getAttribute('data-cat-id');
        if (widget && typeof widget._bkUpdateCategory === 'function') {
            widget._bkUpdateCategory(catId, nextVal, row);
        }
    };
    
    function initBokunWidgets() {
        const containers = document.querySelectorAll('.bokun-booking-widget');
        if (!containers.length) return;
        
        containers.forEach(function(container) {
            var el = container;
            if (container.getAttribute('data-bk-ready') === 'true' || container.getAttribute('data-bk-executed') === 'true' || container.getAttribute('data-bk-initialized') === 'true' || container._bokunInit) return;
            container.setAttribute('data-bk-ready', 'true');
            container.setAttribute('data-bk-initialized', 'true');
            container._bokunInit = true;
            
            const debugLogEl = container.querySelector('.bk-debug-logs');
            const retryBtn = container.querySelector('.bk-debug-retry');
            
            function logDebug(msg, isError) {
                const now = new Date();
                const timeStr = now.toTimeString().split(' ')[0] + '.' + String(now.getMilliseconds()).padStart(3, '0');
                const prefix = isError ? '❌ [ERROR ' + timeStr + '] ' : 'ℹ️ [' + timeStr + '] ';
                console.log(prefix + msg);
                if (debugLogEl) {
                    const line = document.createElement('div');
                    line.style.color = isError ? '#f87171' : '#38bdf8';
                    line.textContent = prefix + msg;
                    debugLogEl.appendChild(line);
                    debugLogEl.scrollTop = debugLogEl.scrollHeight;
                }
            }
            
            logDebug('🚀 [v2.7.3] External Booking Engine Initialized.');

                // 100% Bulletproof 12-Hour AM/PM Observer (Runs instantly & on every DOM change)
                function parseBokunTime(value) {
                    if (typeof value !== 'string') return null;
                    var match = value.trim().match(/^([0-9]{1,2}):([0-9]{2})\s*(AM|PM)?$/i);
                    if (!match) return null;
                    var hour = parseInt(match[1], 10);
                    var minute = parseInt(match[2], 10);
                    var suffix = match[3] ? match[3].toUpperCase() : '';
                    if (minute < 0 || minute > 59) return null;
                    if (suffix) {
                        if (hour < 1 || hour > 12) return null;
                        if (suffix === 'AM' && hour === 12) hour = 0;
                        if (suffix === 'PM' && hour !== 12) hour += 12;
                    } else if (hour < 0 || hour > 23) {
                        return null;
                    }
                    return { hour: hour, minute: minute };
                }

                function formatMilitaryTo12(value) {
                    var parsed = parseBokunTime(value);
                    if (!parsed) return '';
                    var suffix = parsed.hour >= 12 ? 'PM' : 'AM';
                    var hour12 = parsed.hour % 12 || 12;
                    return hour12 + ':' + String(parsed.minute).padStart(2, '0') + ' ' + suffix;
                }

                function scrubAllTimeButtons() {
                    var targets = (el || document).querySelectorAll('.bk-time-btn, .bk-time-value, #bk-summary-time');
                    targets.forEach(function(node) {
                        if (node.classList.contains('bk-time-value') || node.id === 'bk-summary-time') {
                            var cur = node.textContent.trim();
                            var converted = formatMilitaryTo12(cur);
                            if (converted && converted !== cur) {
                                node.textContent = converted;
                                logDebug('⚡ Live Replaced 24h Time: ' + cur + ' -> ' + converted);
                            }
                        } else if (node.classList.contains('bk-time-btn')) {
                            var valSpan = node.querySelector('.bk-time-value');
                            if (valSpan) {
                                var curSpan = valSpan.textContent.trim();
                                var convSpan = formatMilitaryTo12(curSpan);
                                if (convSpan && convSpan !== curSpan) {
                                    valSpan.textContent = convSpan;
                                }
                            }
                        }
                    });
                }

                setInterval(scrubAllTimeButtons, 50);
                if (window.MutationObserver) {
                    var obs = new MutationObserver(function() { scrubAllTimeButtons(); });
                    obs.observe(el || document.body, { childList: true, subtree: true, characterData: true });
                }

            
            let actId = container.getAttribute('data-activity-id');
            if (!actId || actId === '0' || actId === 'undefined') {
                actId = '1317760';
            }
            logDebug('Target Activity ID: ' + actId);
            
            const currency = container.getAttribute('data-currency') || 'QAR';
            let title = container.getAttribute('data-title') || 'The Pearl Kayaking Experience in Doha';
            
            let rawRest = (window.BokunSkipCashConfig && window.BokunSkipCashConfig.restUrl) ? window.BokunSkipCashConfig.restUrl : '/wp-json/bokun-skipcash/v1/';
            logDebug('Configured REST Base URL: ' + rawRest);
            
            function buildEndpointUrl(path, queryString) {
                let url = rawRest;
                if (url.indexOf('/wp-json/bokun-skipcash/v1/') === -1 && url.indexOf('rest_route=') === -1) {
                    url = '/wp-json/bokun-skipcash/v1/';
                }
                if (!url.endsWith('/') && url.indexOf('?') === -1) {
                    url += '/';
                }
                url += path;
                var cacheBust = '_t=' + Date.now();
                if (queryString) {
                    url += (url.indexOf('?') !== -1 ? '&' : '?') + queryString + '&' + cacheBust;
                } else {
                    url += (url.indexOf('?') !== -1 ? '&' : '?') + cacheBust;
                }
                return url;
            }
            
            // Parse initial categories from data attribute or existing DOM rows
            var minPaxReq = parseInt(container.getAttribute('data-min-pax'), 10) || 6;
            var initialCats = [];
            try {
                var initCatsRaw = container.getAttribute('data-initial-categories');
                if (initCatsRaw) {
                    var parsed = JSON.parse(initCatsRaw);
                    if (Array.isArray(parsed) && parsed.length > 0) {
                        var hasDef = parsed.some(function(pc) { return pc.defaultCategory === true; });
                        initialCats = parsed.map(function(pc, idx) {
                            var cid = pc.id || (1000 + idx);
                            var cTitle = pc.title || pc.fullTitle || 'Participant';
                            var isDef = pc.defaultCategory === true || (!hasDef && idx === 0);
                            return {
                                id: cid,
                                title: cTitle,
                                ticketCategory: (pc.ticketCategory || '').toUpperCase(),
                                minAge: pc.minAge,
                                maxAge: pc.maxAge,
                                ageQualified: !!pc.ageQualified,
                                defaultCategory: !!pc.defaultCategory,
                                count: isDef ? minPaxReq : 0,
                                unitPrice: parseFloat(pc.unitPrice || pc.price || 0) || 199.00
                            };
                        });
                    }
                }
            } catch(e) {}

            if (initialCats.length === 0) {
                var domRows = container.querySelectorAll('.bk-category-row');
                if (domRows.length > 0) {
                    domRows.forEach(function(r, idx) {
                        var cid = r.getAttribute('data-cat-id') || (1000 + idx);
                        var titleEl = r.querySelector('.bk-label');
                        var valEl = r.querySelector('.bk-val');
                        var cTitle = titleEl ? titleEl.textContent.trim() : 'Participant';
                        var count = valEl ? (parseInt(valEl.textContent.trim(), 10) || 0) : (idx === 0 ? 1 : 0);
                        var priceAttr = r.getAttribute('data-unit-price');
                        var uPrice = priceAttr ? parseFloat(priceAttr) : 199.00;
                        initialCats.push({
                            id: cid,
                            title: cTitle,
                            count: count,
                            unitPrice: uPrice
                        });
                    });
                } else {
                    initialCats = [{ id: 1001, title: 'Adult', count: 1, unitPrice: 199.00 }];
                }
            }

            // State
            let state = {
                categories: initialCats,
                adults: 1,
                totalParticipants: initialCats.reduce(function(s, c) { return s + c.count; }, 0) || 1,
                currentMonth: new Date(),
                availabilities: [],
                activityData: null,
                selectedDate: null,
                selectedTime: null,
                selectedOption: null
            };
            
            // DOM Elements
            const DOM = {
                categoriesList: container.querySelector('#bk-categories-list'),
                totalPartBadge: container.querySelector('#bk-participants-total-count'),
                adultsVal: container.querySelector('#bk-adults-val') || container.querySelector('.bk-val'),
                
                stepDate: container.querySelector('#bk-step-date'),
                calMonthLbl: container.querySelector('#bk-cal-month-label'),
                calPrev: container.querySelector('.bk-cal-prev'),
                calNext: container.querySelector('.bk-cal-next'),
                calGrid: container.querySelector('#bk-cal-grid'),
                
                stepTime: container.querySelector('#bk-step-time'),
                btnBackDate: container.querySelector('#bk-back-to-date'),
                selectedDateLbl: container.querySelector('#bk-selected-date-lbl'),
                timeGrid: container.querySelector('#bk-time-grid'),
                
                stepOptions: container.querySelector('#bk-step-options'),
                optCount: container.querySelector('#bk-opt-count'),
                optList: container.querySelector('#bk-options-list'),
                
                stepContact: container.querySelector('#bk-step-contact'),
                
                sumTitle: container.querySelector('#bk-summary-event-title'),
                sumAdults: container.querySelector('#bk-summary-adults'),
                sumTime: container.querySelector('#bk-summary-time'),
                sumDate: container.querySelector('#bk-summary-date'),
                sumTotal: container.querySelector('#bk-summary-total'),
                
                btnCheckout: container.querySelector('#bk-checkout-btn'),
                
                fName: container.querySelector('#bk-first-name'),
                lName: container.querySelector('#bk-last-name'),
                email: container.querySelector('#bk-email'),
                countryCode: container.querySelector('#bk-country-code'),
                phone: container.querySelector('#bk-phone'),
                errMsg: container.querySelector('#bk-error-msg'),
                countryBtn: container.querySelector('#bk-country-btn'),
                countryMenu: container.querySelector('#bk-country-menu'),
                countryListEl: container.querySelector('#bk-country-list'),
                countrySearch: container.querySelector('#bk-country-search'),
                countryLabel: container.querySelector('#bk-selected-country-label')
            };

            function renderCategories() {
                if (!DOM.categoriesList) return;
                DOM.categoriesList.innerHTML = '';
                
                state.categories.forEach(function(cat) {
                    var row = document.createElement('div');
                    row.className = 'bk-category-row';
                    row.setAttribute('data-cat-id', String(cat.id));
                    row.style.cssText = 'display: flex !important; justify-content: space-between !important; align-items: center !important; padding: 10px 0 !important; border-bottom: 1px solid #f1f5f9 !important;';
                    
                    var ageLabel = '';
                    var minA = (cat.minAge !== undefined && cat.minAge !== null) ? Number(cat.minAge) : 0;
                    var maxA = (cat.maxAge !== undefined && cat.maxAge !== null) ? Number(cat.maxAge) : 0;
                    
                    if (cat.ageQualified || minA > 0 || maxA > 0) {
                        if (minA > 0 && maxA > 0) {
                            ageLabel = 'Age ' + minA + ' - ' + maxA;
                        } else if (minA > 0) {
                            ageLabel = 'Age ' + minA + '+';
                        } else if (maxA > 0) {
                            ageLabel = 'Age up to ' + maxA;
                        }
                    }
                    
                    var priceTag = '';
                    if (cat.unitPrice !== undefined && cat.unitPrice > 0) {
                        priceTag = '<span style="font-size: 13px !important; color: #800020 !important; font-weight: 700 !important; margin-left: 6px !important;">' + currency + ' ' + Number(cat.unitPrice).toFixed(0) + '</span>' +
                                   '<span class="bk-cat-subtotal" style="font-size: 12px !important; color: #64748b !important; font-weight: 600 !important; margin-left: 4px !important;' + (cat.count > 1 ? '' : 'display:none!important;') + '">(' + currency + ' ' + (cat.unitPrice * cat.count).toFixed(0) + ')</span>';
                    } else if (cat.unitPrice === 0) {
                        priceTag = '<span style="font-size: 12px !important; color: #16a34a !important; font-weight: 700 !important; margin-left: 6px !important;">Free</span>';
                    }
                    
                    row.innerHTML = '<div>' +
                        '<div style="display: flex !important; align-items: baseline !important; gap: 4px !important;">' +
                            '<span class="bk-label" style="font-size: 15px !important; font-weight: 700 !important; color: #1e293b !important;">' + cat.title + '</span>' +
                            priceTag +
                        '</div>' +
                        (ageLabel ? '<span class="bk-cat-age" style="font-size: 12px !important; color: #64748b !important;">' + ageLabel + '</span>' : '') +
                    '</div>' +
                    '<div class="bk-counter-pill" style="display: inline-flex !important; flex-direction: row !important; align-items: center !important; justify-content: space-between !important; width: 110px !important; height: 38px !important; border: 1.8px solid #800020 !important; border-radius: 20px !important; padding: 0 8px !important; background: #ffffff !important; box-sizing: border-box !important;">' +
                        '<button type="button" class="bk-btn-minus" data-action="minus" onclick="if(window.bkChangeCategory)window.bkChangeCategory(event, this, -1);" aria-label="Decrease ' + cat.title + '" style="width: 26px !important; height: 26px !important; background: transparent !important; border: none !important; color: #800020 !important; cursor: pointer !important; padding: 0 !important; margin: 0 !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; border-radius: 50% !important;">' +
                        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none!important;"><line x1="5" y1="12" x2="19" y2="12"></line></svg>' +
                        '</button>' +
                        '<span class="bk-val" style="font-size: 16px !important; font-weight: 800 !important; color: #0f172a !important; min-width: 20px !important; text-align: center !important; display: inline-block !important; user-select:none!important;">' + cat.count + '</span>' +
                        '<button type="button" class="bk-btn-plus" data-action="plus" onclick="if(window.bkChangeCategory)window.bkChangeCategory(event, this, 1);" aria-label="Increase ' + cat.title + '" style="width: 26px !important; height: 26px !important; background: transparent !important; border: none !important; color: #800020 !important; cursor: pointer !important; padding: 0 !important; margin: 0 !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; border-radius: 50% !important;">' +
                        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none!important;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>' +
                        '</button>' +
                    '</div>';
                    
                    DOM.categoriesList.appendChild(row);
                });
            }

            container._bkUpdateCategory = function(catId, count, row) {
                var cat = state.categories.find(function(c) { return String(c.id) === String(catId); });
                if (!cat) {
                    var titleEl = row ? row.querySelector('.bk-label') : null;
                    cat = {
                        id: catId,
                        title: titleEl ? titleEl.textContent.trim() : 'Participant',
                        count: count,
                        unitPrice: 199.00
                    };
                    state.categories.push(cat);
                } else {
                    cat.count = count;
                }
                
                var targetRow = row || container.querySelector('.bk-category-row[data-cat-id="' + catId + '"]');
                if (targetRow) {
                    var valEl = targetRow.querySelector('.bk-val');
                    if (valEl) valEl.textContent = cat.count;
                    
                    var subEl = targetRow.querySelector('.bk-cat-subtotal');
                    if (subEl) {
                        if (cat.count > 1 && cat.unitPrice > 0) {
                            subEl.textContent = ' (' + currency + ' ' + (cat.count * cat.unitPrice).toFixed(0) + ')';
                            subEl.style.setProperty('display', 'inline', 'important');
                        } else {
                            subEl.textContent = '';
                            subEl.style.setProperty('display', 'none', 'important');
                        }
                    }
                }
                
                if (state.selectedTime) renderOptions();
                updateSummary();
                buildGrid(state.currentMonth.getFullYear(), state.currentMonth.getMonth());
            };

            // Delegated category counter listener on categories list
            if (DOM.categoriesList && !DOM.categoriesList._hasClickBound) {
                DOM.categoriesList._hasClickBound = true;
                DOM.categoriesList.addEventListener('click', function(e) {
                    var btn = e.target.closest('button');
                    if (!btn) return;
                    var row = btn.closest('.bk-category-row');
                    if (!row) return;
                    var catId = row.getAttribute('data-cat-id');
                    var cat = state.categories.find(function(c) { return String(c.id) === String(catId); });
                    
                    if (!cat) {
                        var valElFallback = row.querySelector('.bk-val');
                        var curVal = valElFallback ? parseInt(valElFallback.textContent.trim(), 10) || 0 : 0;
                        var titleElFallback = row.querySelector('.bk-label');
                        var fallbackTitle = titleElFallback ? titleElFallback.textContent.trim() : 'Participant';
                        cat = {
                            id: catId || ('cat_' + Date.now()),
                            title: fallbackTitle,
                            count: curVal,
                            unitPrice: 199.00
                        };
                        state.categories.push(cat);
                    }
                    
                    var isPlus = btn.classList.contains('bk-btn-plus') || btn.getAttribute('data-action') === 'plus';
                    var isMinus = btn.classList.contains('bk-btn-minus') || btn.getAttribute('data-action') === 'minus';
                    
                    if (isPlus) {
                        if (e) { e.preventDefault(); e.stopPropagation(); }
                        cat.count = (parseInt(cat.count, 10) || 0) + 1;
                    } else if (isMinus) {
                        if (e) { e.preventDefault(); e.stopPropagation(); }
                        if (cat.count > 0) {
                            cat.count = (parseInt(cat.count, 10) || 0) - 1;
                        }
                    } else {
                        return;
                    }
                    
                    var valEl = row.querySelector('.bk-val');
                    if (valEl) valEl.textContent = cat.count;
                    
                    var subEl = row.querySelector('.bk-cat-subtotal');
                    if (subEl) {
                        if (cat.count > 1 && cat.unitPrice > 0) {
                            subEl.textContent = ' (' + currency + ' ' + (cat.count * cat.unitPrice).toFixed(0) + ')';
                            subEl.style.setProperty('display', 'inline', 'important');
                        } else {
                            subEl.textContent = '';
                            subEl.style.setProperty('display', 'none', 'important');
                        }
                    }
                    
                    updateSummary();
                    buildGrid(state.currentMonth.getFullYear(), state.currentMonth.getMonth());
                    if (state.selectedTime) renderOptions();
                });
            }

            // Country Searchable Dropdown Management
            var COUNTRIES = [
                { name: 'Qatar', code: 'QA', dialCode: '+974', placeholder: '3300 1234' },
                { name: 'Saudi Arabia', code: 'SA', dialCode: '+966', placeholder: '50 123 4567' },
                { name: 'United Arab Emirates', code: 'AE', dialCode: '+971', placeholder: '50 123 4567' },
                { name: 'Kuwait', code: 'KW', dialCode: '+965', placeholder: '9123 4567' },
                { name: 'Bahrain', code: 'BH', dialCode: '+973', placeholder: '3600 1234' },
                { name: 'Oman', code: 'OM', dialCode: '+968', placeholder: '9123 4567' },
                { name: 'United Kingdom', code: 'GB', dialCode: '+44', placeholder: '7911 123456' },
                { name: 'United States', code: 'US', dialCode: '+1', placeholder: '(555) 012-3456' },
                { name: 'Canada', code: 'CA', dialCode: '+1', placeholder: '(555) 012-3456' },
                { name: 'India', code: 'IN', dialCode: '+91', placeholder: '98765 43210' },
                { name: 'Pakistan', code: 'PK', dialCode: '+92', placeholder: '300 1234567' },
                { name: 'Egypt', code: 'EG', dialCode: '+20', placeholder: '100 123 4567' },
                { name: 'Jordan', code: 'JO', dialCode: '+962', placeholder: '7 9012 3456' },
                { name: 'Lebanon', code: 'LB', dialCode: '+961', placeholder: '70 123 456' },
                { name: 'Philippines', code: 'PH', dialCode: '+63', placeholder: '917 123 4567' },
                { name: 'Germany', code: 'DE', dialCode: '+49', placeholder: '151 12345678' },
                { name: 'France', code: 'FR', dialCode: '+33', placeholder: '6 12 34 56 78' },
                { name: 'Italy', code: 'IT', dialCode: '+39', placeholder: '320 123 4567' },
                { name: 'Spain', code: 'ES', dialCode: '+34', placeholder: '612 34 56 78' },
                { name: 'Turkey', code: 'TR', dialCode: '+90', placeholder: '532 123 4567' },
                { name: 'Australia', code: 'AU', dialCode: '+61', placeholder: '412 345 678' },
                { name: 'Singapore', code: 'SG', dialCode: '+65', placeholder: '8123 4567' },
                { name: 'Malaysia', code: 'MY', dialCode: '+60', placeholder: '12-345 6789' },
                { name: 'Indonesia', code: 'ID', dialCode: '+62', placeholder: '812-3456-7890' },
                { name: 'Bangladesh', code: 'BD', dialCode: '+880', placeholder: '1712-345678' },
                { name: 'Sri Lanka', code: 'LK', dialCode: '+94', placeholder: '71 234 5678' },
                { name: 'Nepal', code: 'NP', dialCode: '+977', placeholder: '984-1234567' },
                { name: 'South Africa', code: 'ZA', dialCode: '+27', placeholder: '71 123 4567' },
                { name: 'China', code: 'CN', dialCode: '+86', placeholder: '138 0013 8000' },
                { name: 'Japan', code: 'JP', dialCode: '+81', placeholder: '90-1234-5678' },
                { name: 'South Korea', code: 'KR', dialCode: '+82', placeholder: '10-1234-5678' },
                { name: 'Netherlands', code: 'NL', dialCode: '+31', placeholder: '6 12345678' },
                { name: 'Switzerland', code: 'CH', dialCode: '+41', placeholder: '78 123 45 67' }
            ];
            var currentCountry = COUNTRIES[0];

            function renderCountryList(filterQuery) {
                if (!DOM.countryListEl) return;
                DOM.countryListEl.innerHTML = '';
                var q = (filterQuery || '').toLowerCase().trim();
                var matches = COUNTRIES.filter(function(c) {
                    if (!q) return true;
                    return c.name.toLowerCase().indexOf(q) !== -1 || c.dialCode.indexOf(q) !== -1 || c.code.toLowerCase().indexOf(q) !== -1;
                });
                if (matches.length === 0) {
                    var noRes = document.createElement('div');
                    noRes.style.cssText = 'padding: 16px !important; text-align: center !important; font-size: 12px !important; color: #94a3b8 !important;';
                    noRes.textContent = 'No country found';
                    DOM.countryListEl.appendChild(noRes);
                    return;
                }
                matches.forEach(function(c) {
                    var isSel = currentCountry && currentCountry.code === c.code;
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'bk-country-item' + (isSel ? ' selected' : '');
                    btn.style.cssText = 'width: 100% !important; padding: 10px 14px !important; text-align: left !important; display: flex !important; align-items: center !important; justify-content: space-between !important; font-size: 13px !important; border: none !important; border-bottom: 1px solid #f8fafc !important; cursor: pointer !important; background: ' + (isSel ? '#fff1f2' : '#ffffff') + ' !important; color: ' + (isSel ? '#800020' : '#1e293b') + ' !important; font-weight: ' + (isSel ? '700' : '400') + ' !important; box-sizing: border-box !important;';
                    
                    btn.innerHTML = '<span style="overflow: hidden !important; text-overflow: ellipsis !important; white-space: nowrap !important;">' + c.name + ' (' + c.dialCode + ')</span>' +
                        (isSel ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0!important; margin-left:8px!important;"><polyline points="20 6 9 17 4 12"></polyline></svg>' : '');
                        
                    btn.addEventListener('click', function(e) {
                        if (e) { e.preventDefault(); e.stopPropagation(); }
                        currentCountry = c;
                        if (DOM.countryCode) DOM.countryCode.value = c.dialCode;
                        if (DOM.countryLabel) DOM.countryLabel.textContent = c.name + ' (' + c.dialCode + ')';
                        if (DOM.phone) DOM.phone.placeholder = c.placeholder;
                        if (DOM.countryMenu) DOM.countryMenu.classList.add('bk-hidden');
                        if (DOM.countrySearch) DOM.countrySearch.value = '';
                    });
                    DOM.countryListEl.appendChild(btn);
                });
            }

            if (DOM.countryBtn && DOM.countryMenu) {
                DOM.countryBtn.addEventListener('click', function(e) {
                    if (e) { e.preventDefault(); e.stopPropagation(); }
                    var isHidden = DOM.countryMenu.classList.contains('bk-hidden');
                    if (isHidden) {
                        DOM.countryMenu.classList.remove('bk-hidden');
                        renderCountryList('');
                        if (DOM.countrySearch) {
                            setTimeout(function() { DOM.countrySearch.focus(); }, 50);
                        }
                    } else {
                        DOM.countryMenu.classList.add('bk-hidden');
                    }
                });
            }

            if (DOM.countrySearch) {
                DOM.countrySearch.addEventListener('input', function(e) {
                    renderCountryList(e.target.value);
                });
                DOM.countrySearch.addEventListener('click', function(e) {
                    if (e) e.stopPropagation();
                });
            }

            document.addEventListener('click', function(e) {
                if (DOM.countryMenu && !DOM.countryMenu.classList.contains('bk-hidden')) {
                    var wrapper = container.querySelector('.bk-country-dropdown-wrapper');
                    if (wrapper && !wrapper.contains(e.target)) {
                        DOM.countryMenu.classList.add('bk-hidden');
                    }
                }
            });

            renderCountryList('');
            
            state.currentMonth.setDate(1);
            state.minCalendarMonth = new Date(state.currentMonth.getTime());
            state.minCalendarMonth.setDate(1);
            
            // Render grid immediately so calendar is NEVER empty
            buildGrid(state.currentMonth.getFullYear(), state.currentMonth.getMonth());
            
            // Initial Calendar & Activity Details Fetch
            fetchActivityDetails();
            renderCalendar();
            
            if (retryBtn) {
                retryBtn.addEventListener('click', function() {
                    logDebug('Manual Force Refresh triggered by user...');
                    fetchActivityDetails();
                    renderCalendar();
                });
            }
            
            // Navigation & Form Event Listeners
            if (DOM.calPrev) {
                DOM.calPrev.addEventListener('click', function() {
                    var previousMonth = new Date(state.currentMonth.getTime());
                    previousMonth.setMonth(previousMonth.getMonth() - 1);
                    previousMonth.setDate(1);
                    if (state.minCalendarMonth && previousMonth < state.minCalendarMonth) return;
                    state.currentMonth = previousMonth;
                    renderCalendar();
                });
            }
            if (DOM.calNext) {
                DOM.calNext.addEventListener('click', function() {
                    state.currentMonth.setMonth(state.currentMonth.getMonth() + 1);
                    renderCalendar();
                });
            }
            
            if (DOM.btnBackDate) {
                DOM.btnBackDate.addEventListener('click', function() {
                    state.selectedDate = null;
                    state.selectedTime = null;
                    state.selectedOption = null;
                    if (DOM.stepTime) DOM.stepTime.classList.add('bk-hidden');
                    if (DOM.stepOptions) DOM.stepOptions.classList.add('bk-hidden');
                    if (DOM.stepContact) DOM.stepContact.classList.add('bk-hidden');
                    if (DOM.stepDate) DOM.stepDate.classList.remove('bk-hidden');
                    updateSummary();
                });
            }
            
            if (DOM.btnCheckout) {
                DOM.btnCheckout.addEventListener('click', handleCheckout);
            }
            
            function updateCategoriesFromData(pricingCategories, slotPrices) {
                if (!Array.isArray(pricingCategories) || pricingCategories.length === 0) return;
                
                var minPaxReq = parseInt(container.getAttribute('data-min-pax'), 10) || 6;
                var existingCounts = {};
                state.categories.forEach(function(c, idx) {
                    existingCounts[String(c.id)] = c.count;
                    existingCounts[c.title.toLowerCase()] = c.count;
                    existingCounts[c.title.toLowerCase().replace(/s$/, '')] = c.count;
                    existingCounts['idx_' + idx] = c.count;
                    if (c.ticketCategory) existingCounts[c.ticketCategory.toLowerCase()] = c.count;
                });

                var hasExplicitDefault = pricingCategories.some(function(pc) { return pc.defaultCategory === true; });
                
                var newCats = [];
                pricingCategories.forEach(function(pc, idx) {
                    var cid = pc.id || (1000 + idx);
                    var title = pc.title || pc.name || pc.fullTitle || 'Participant';
                    var cleanTitle = title.toLowerCase().trim();
                    var cleanSingular = cleanTitle.replace(/s$/, '');
                    var tCat = (pc.ticketCategory || '').toUpperCase();
                    
                    var minAge = (pc.minAge !== undefined && pc.minAge !== null) ? parseInt(pc.minAge, 10) : 0;
                    var maxAge = (pc.maxAge !== undefined && pc.maxAge !== null) ? parseInt(pc.maxAge, 10) : 0;
                    
                    var isDef = pc.defaultCategory === true || (!hasExplicitDefault && idx === 0);
                    var defaultCount = isDef ? minPaxReq : 0;
                    
                    var count = defaultCount;
                    if (existingCounts[String(cid)] !== undefined) {
                        count = existingCounts[String(cid)];
                    } else if (existingCounts[cleanTitle] !== undefined) {
                        count = existingCounts[cleanTitle];
                    } else if (existingCounts[cleanSingular] !== undefined) {
                        count = existingCounts[cleanSingular];
                    } else if (tCat && existingCounts[tCat.toLowerCase()] !== undefined) {
                        count = existingCounts[tCat.toLowerCase()];
                    } else if (existingCounts['idx_' + idx] !== undefined) {
                        count = existingCounts['idx_' + idx];
                    }

                    if (isDef && count < minPaxReq) {
                        count = minPaxReq;
                    }
                    
                    var unitPrice = 199.00;
                    if (pc.unitPrice !== undefined && pc.unitPrice !== null && parseFloat(pc.unitPrice) > 0) {
                        unitPrice = parseFloat(pc.unitPrice);
                    } else if (pc.price !== undefined && pc.price !== null && parseFloat(pc.price) > 0) {
                        unitPrice = parseFloat(pc.price);
                    } else if (slotPrices && slotPrices[cid] !== undefined && parseFloat(slotPrices[cid]) > 0) {
                        unitPrice = parseFloat(slotPrices[cid]);
                    } else if (cleanTitle.indexOf('child') !== -1) {
                        unitPrice = 99.00;
                    } else if (cleanTitle.indexOf('infant') !== -1) {
                        unitPrice = 0.00;
                    }
                    
                    newCats.push({
                        id: cid,
                        title: title,
                        ticketCategory: tCat,
                        minAge: minAge > 0 ? minAge : undefined,
                        maxAge: maxAge > 0 ? maxAge : undefined,
                        ageQualified: !!pc.ageQualified,
                        defaultCategory: !!pc.defaultCategory,
                        count: count,
                        unitPrice: unitPrice
                    });
                });
                
                // Non-destructively preserve any existing categories not in the incoming payload
                state.categories.forEach(function(existingC) {
                    var alreadyInNew = newCats.some(function(nc) { return String(nc.id) === String(existingC.id); });
                    if (!alreadyInNew) {
                        newCats.push(existingC);
                    }
                });

                var totalC = newCats.reduce(function(sum, c) { return sum + c.count; }, 0);
                if (totalC < minPaxReq && newCats.length > 0) {
                    var defCat = newCats.find(function(c) { return c.defaultCategory; }) || newCats[0];
                    if (defCat) defCat.count = Math.max(minPaxReq, defCat.count);
                }
                
                state.categories = newCats;
                renderCategories();
                updateSummary();
            }

            function fetchActivityDetails() {
                var url = buildEndpointUrl('activity/' + actId, 'refresh=1');
                var catsUrl = buildEndpointUrl('categories', 'activity_id=' + actId + '&refresh=1');
                logDebug('Fetching Activity details & categories: ' + url);
                
                Promise.all([
                    fetch(url, { cache: 'no-store' }).then(function(r) { return r.json(); }).catch(function() { return null; }),
                    fetch(catsUrl, { cache: 'no-store' }).then(function(r) { return r.json(); }).catch(function() { return null; })
                ])
                .then(function(resArray) {
                    var res = resArray[0] || {};
                    var catsRes = resArray[1] || {};

                    if (catsRes && Array.isArray(catsRes.categories) && catsRes.categories.length > 0) {
                        logDebug('Categories API loaded: ' + catsRes.categories.length + ' categories');
                        updateCategoriesFromData(catsRes.categories, {});
                    }

                    if (res.success && res.activity) {
                        logDebug('Activity Details loaded: ' + (res.activity.title || 'OK'));
                        state.activityData = res.activity;
                        if (res.activity.title) {
                            title = res.activity.title;
                            if (DOM.sumTitle) DOM.sumTitle.textContent = title;
                        }
                        if (res.activity.pricingCategories && Array.isArray(res.activity.pricingCategories)) {
                            updateCategoriesFromData(res.activity.pricingCategories, {});
                        }
                    } else {
                        logDebug('Activity Details notice: ' + (res.message || 'No extra data'), true);
                    }
                })
                .catch(function(err) {
                    logDebug('Activity details fetch error: ' + err.message, true);
                });
            }
            
            function updateSummary() {
                var totalGuests = 0;
                var breakdownParts = [];
                var customSum = 0;
                
                state.categories.forEach(function(c) {
                    if (c.count > 0) {
                        totalGuests += c.count;
                        var cTitle = c.title || '';
                        if (c.count === 1) {
                            if (cTitle.toLowerCase().indexOf('child') !== -1) cTitle = 'Child';
                            else if (cTitle.endsWith('s')) cTitle = cTitle.slice(0, -1);
                        } else {
                            if (cTitle.toLowerCase().indexOf('child') !== -1) cTitle = 'Children';
                            else if (!cTitle.endsWith('s')) cTitle = cTitle + 's';
                        }
                        breakdownParts.push(c.count + ' ' + cTitle);
                        if (c.unitPrice > 0) {
                            customSum += (c.unitPrice * c.count);
                        }
                    }
                });
                if (totalGuests === 0) totalGuests = 1;
                state.totalParticipants = totalGuests;
                
                var adultsCat = state.categories.find(function(c) { return c.defaultCategory || c.title.toLowerCase().indexOf('adult') !== -1; });
                state.adults = adultsCat ? adultsCat.count : totalGuests;
                
                var totalPartEl = container.querySelector('#bk-participants-total-count');
                if (totalPartEl) totalPartEl.textContent = state.totalParticipants;
                
                var adultsValEl = container.querySelector('#bk-adults-val');
                if (adultsValEl) adultsValEl.textContent = state.adults;
                
                if (DOM.sumAdults) {
                    DOM.sumAdults.textContent = breakdownParts.length > 0 ? breakdownParts.join(', ') : (state.adults + ' Adult' + (state.adults > 1 ? 's' : ''));
                }
                
                if (state.selectedDate) {
                    const parts = state.selectedDate.split('-');
                    const d = new Date(parts[0], parts[1] - 1, parts[2]);
                    if (DOM.sumDate) DOM.sumDate.textContent = d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
                } else {
                    if (DOM.sumDate) DOM.sumDate.textContent = 'Select a date';
                }
                
                if (state.selectedTime) {
                    if (DOM.sumTime) DOM.sumTime.textContent = state.selectedTime.displayTimeLabel || state.selectedTime.startTime;
                } else {
                    if (DOM.sumTime) DOM.sumTime.textContent = '--:--';
                }
                
                var calculatedTotal = 0;
                if (customSum > 0) {
                    calculatedTotal = customSum;
                    if (state.selectedOption) {
                        state.selectedOption.totalPrice = customSum;
                        state.selectedOption.price = customSum / Math.max(1, totalGuests);
                    }
                } else if (state.selectedOption && state.selectedOption.totalPrice !== undefined && state.selectedOption.totalPrice > 0) {
                    calculatedTotal = state.selectedOption.totalPrice;
                } else if (state.selectedOption && state.selectedOption.price) {
                    calculatedTotal = (state.selectedOption.price * Math.max(1, totalGuests));
                } else {
                    calculatedTotal = 199.00 * Math.max(1, totalGuests);
                }
                if (DOM.sumTotal) DOM.sumTotal.textContent = currency + ' ' + calculatedTotal.toFixed(2);
                if (DOM.btnCheckout) {
                    var minPaxLimit = state.selectedOption && state.selectedOption.minPerBooking ? parseInt(state.selectedOption.minPerBooking, 10) : 0;
                    var maxPaxLimit = state.selectedOption && state.selectedOption.maxPerBooking ? parseInt(state.selectedOption.maxPerBooking, 10) : 0;
                    
                    if (minPaxLimit > 0 && totalGuests < minPaxLimit) {
                        DOM.btnCheckout.disabled = true;
                        DOM.btnCheckout.style.setProperty('opacity', '0.6', 'important');
                        DOM.btnCheckout.style.setProperty('cursor', 'not-allowed', 'important');
                        DOM.btnCheckout.innerHTML = '<span>Min ' + minPaxLimit + ' Guests Required</span>';
                    } else if (maxPaxLimit > 0 && totalGuests > maxPaxLimit) {
                        DOM.btnCheckout.disabled = true;
                        DOM.btnCheckout.style.setProperty('opacity', '0.6', 'important');
                        DOM.btnCheckout.style.setProperty('cursor', 'not-allowed', 'important');
                        DOM.btnCheckout.innerHTML = '<span>Max ' + maxPaxLimit + ' Guests Allowed</span>';
                    } else {
                        DOM.btnCheckout.disabled = false;
                        DOM.btnCheckout.style.removeProperty('opacity');
                        DOM.btnCheckout.style.removeProperty('cursor');
                        DOM.btnCheckout.innerHTML = '<span>Book & Pay ' + currency + ' ' + calculatedTotal.toFixed(2) + '</span> <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>';
                    }
                }
                if (DOM.stepContact) DOM.stepContact.classList.remove('bk-hidden');
                
                if (state.selectedTime && !state.selectedOption) {
                    renderOptions();
                }
            }
            
            function extractYYYYMMDD(slot) {
                if (!slot) return null;
                
                // 1. Direct string checking or ISO date format (e.g., "2026-09-12" or "2026-9-12")
                if (typeof slot === 'string') {
                    var mStr = slot.match(/(20[0-9]{2})[-/](0?[1-9]|1[0-2])[-/](0?[1-9]|[12][0-9]|3[01])/);
                    if (mStr) return mStr[1] + '-' + String(mStr[2]).padStart(2, '0') + '-' + String(mStr[3]).padStart(2, '0');
                }

                // 2. Check property 'date'
                if (slot.date !== undefined && slot.date !== null) {
                    if (typeof slot.date === 'string') {
                        var mDate = slot.date.match(/(20[0-9]{2})[-/](0?[1-9]|1[0-2])[-/](0?[1-9]|[12][0-9]|3[01])/);
                        if (mDate) return mDate[1] + '-' + String(mDate[2]).padStart(2, '0') + '-' + String(mDate[3]).padStart(2, '0');
                        var mDateCompact = slot.date.match(/(20[0-9]{2})(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])/);
                        if (mDateCompact) return mDateCompact[1] + '-' + String(mDateCompact[2]).padStart(2, '0') + '-' + String(mDateCompact[3]).padStart(2, '0');
                    } else if (typeof slot.date === 'number') {
                        var ms = slot.date < 10000000000 ? slot.date * 1000 : slot.date;
                        var d = new Date(ms);
                        if (!isNaN(d.getTime())) {
                            var y = d.getUTCFullYear();
                            var m = String(d.getUTCMonth() + 1).padStart(2, '0');
                            var day = String(d.getUTCDate()).padStart(2, '0');
                            return y + '-' + m + '-' + day;
                        }
                    }
                }

                // 3. Check common date properties
                var propNames = ['start', 'startTime', 'dateTime', 'dateStr', 'formattedDate', 'localizedDate', 'startDate'];
                for (var i = 0; i < propNames.length; i++) {
                    var pVal = slot[propNames[i]];
                    if (typeof pVal === 'string') {
                        var mProp = pVal.match(/(20[0-9]{2})[-/](0?[1-9]|1[0-2])[-/](0?[1-9]|[12][0-9]|3[01])/);
                        if (mProp) return mProp[1] + '-' + String(mProp[2]).padStart(2, '0') + '-' + String(mProp[3]).padStart(2, '0');
                    }
                }

                // 4. Check 'id' property (e.g. "1317760_20260912_5890195" or "20260912")
                if (slot.id) {
                    var strId = String(slot.id);
                    var mId = strId.match(/(20[0-9]{2})(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])/);
                    if (mId) return mId[1] + '-' + String(mId[2]).padStart(2, '0') + '-' + String(mId[3]).padStart(2, '0');
                }

                // 5. Deep property search fallback
                for (var key in slot) {
                    if (typeof slot[key] === 'string') {
                        var mKey = slot[key].match(/(20[0-9]{2})[-/](0?[1-9]|1[0-2])[-/](0?[1-9]|[12][0-9]|3[01])/);
                        if (mKey) return mKey[1] + '-' + String(mKey[2]).padStart(2, '0') + '-' + String(mKey[3]).padStart(2, '0');
                        var mKey2 = slot[key].match(/(20[0-9]{2})(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])/);
                        if (mKey2) return mKey2[1] + '-' + String(mKey2[2]).padStart(2, '0') + '-' + String(mKey2[3]).padStart(2, '0');
                    }
                }

                return null;
            }
            
            function getLowestPrice(slot) {
                if (!slot) return 199.00;
                let lowest = 999999;
                
                try {
                    if (Array.isArray(slot.pricesByRate)) {
                        slot.pricesByRate.forEach(function(pbr) {
                            if (pbr && Array.isArray(pbr.pricePerCategoryUnit)) {
                                pbr.pricePerCategoryUnit.forEach(function(cat) {
                                    if (cat && cat.amount) {
                                        var a = typeof cat.amount === 'object' ? cat.amount.amount : cat.amount;
                                        var num = parseFloat(a);
                                        if (!isNaN(num) && num > 0) lowest = Math.min(lowest, num);
                                    }
                                });
                            }
                            if (pbr && pbr.pricePerBooking) {
                                var b = typeof pbr.pricePerBooking === 'object' ? pbr.pricePerBooking.amount : pbr.pricePerBooking;
                                var numB = parseFloat(b);
                                if (!isNaN(numB) && numB > 0) lowest = Math.min(lowest, numB);
                            }
                        });
                    }
                    
                    var keys = ['price', 'minPrice', 'defaultPrice', 'startingPrice', 'amount'];
                    for (var i = 0; i < keys.length; i++) {
                        if (slot[keys[i]] !== undefined && slot[keys[i]] !== null) {
                            var pVal = parseFloat(slot[keys[i]]);
                            if (!isNaN(pVal) && pVal > 0) {
                                lowest = Math.min(lowest, pVal);
                            }
                        }
                    }
                } catch(e) {}
                
                if (lowest === 999999 || lowest <= 0 || isNaN(lowest)) lowest = 199.00;
                return typeof lowest === 'number' ? lowest : 199.00;
            }

var isPastCutoff = function(s, dStr) {
                if (!s) return false;
                var d = dStr;
                if (!d && s.date) {
                    var dt = new Date(s.date < 10000000000 ? s.date * 1000 : s.date);
                    d = dt.getUTCFullYear() + '-' + String(dt.getUTCMonth() + 1).padStart(2, '0') + '-' + String(dt.getUTCDate()).padStart(2, '0');
                }
                if (!d) return false;

                var rawTime = String(s.startTime || s.time || '').trim();
                var customLabel = String(s.startTimeLabel || s.label || s.timeLabel || s.title || '').trim().toLowerCase();
                var stId = parseInt(s.startTimeId || (s.id ? String(s.id).split('_')[0] : 0), 10);

                var hr = 0;
                var min = 0;
                var hasTime = false;

                var parsedTime = parseBokunTime(rawTime);
                if (parsedTime) {
                    hr = parsedTime.hour;
                    min = parsedTime.minute;
                    hasTime = true;
                } else if (stId === 5890195 || customLabel.indexOf('sunrise') !== -1) {
                    hr = 5; min = 0; hasTime = true;
                } else if (stId === 5890196 || customLabel.indexOf('morning') !== -1) {
                    hr = 7; min = 0; hasTime = true;
                } else if (stId === 5890197 || customLabel.indexOf('sunset') !== -1) {
                    hr = 16; min = 30; hasTime = true;
                }

                var dParts = d.split('-');
                if (dParts.length < 3) return false;
                var y = parseInt(dParts[0], 10);
                var m = parseInt(dParts[1], 10) - 1;
                var day = parseInt(dParts[2], 10);

                if (!hasTime) { hr = 23; min = 59; }

                var slotDateTime = new Date(y, m, day, hr, min, 0).getTime();
                if (!slotDateTime || isNaN(slotDateTime)) return false;

                var bookingCutoffMins = 0;
                if (typeof container !== 'undefined' && container && typeof container.getAttribute === 'function') {
                    bookingCutoffMins = parseInt(container.getAttribute('data-booking-cutoff') || (state && state.bookingCutoff) || 0, 10);
                } else if (typeof state !== 'undefined' && state) {
                    bookingCutoffMins = parseInt(state.bookingCutoff || 0, 10);
                }

                var nowMs = Date.now();
                var cutoffMs = bookingCutoffMins * 60 * 1000;
                return (slotDateTime <= nowMs) || (slotDateTime - nowMs < cutoffMs);
            };
            
            function renderCalendar() {
                if (state.minCalendarMonth && state.currentMonth < state.minCalendarMonth) {
                    state.currentMonth = new Date(state.minCalendarMonth.getTime());
                }
                const year = state.currentMonth.getFullYear();
                const month = state.currentMonth.getMonth();
                
                if (DOM.calMonthLbl) DOM.calMonthLbl.textContent = state.currentMonth.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                if (DOM.calPrev) {
                    var atMinimumMonth = !state.minCalendarMonth || state.currentMonth <= state.minCalendarMonth;
                    DOM.calPrev.disabled = atMinimumMonth;
                    DOM.calPrev.setAttribute('aria-disabled', atMinimumMonth ? 'true' : 'false');
                    DOM.calPrev.style.opacity = atMinimumMonth ? '0.45' : '1';
                    DOM.calPrev.style.cursor = atMinimumMonth ? 'not-allowed' : 'pointer';
                }
                
                const startStr = year + '-' + String(month + 1).padStart(2, '0') + '-01';
                const lastDayNum = new Date(year, month + 1, 0).getDate();
                const endStr = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(lastDayNum).padStart(2, '0');
                
                var url = buildEndpointUrl('availabilities', 'activity_id=' + actId + '&start=' + startStr + '&end=' + endStr);
                logDebug('Fetching Availabilities: ' + url);
                
                fetch(url, { cache: 'no-store' })
                .then(function(r) {
                    logDebug('Availabilities HTTP Status: ' + r.status + ' ' + r.statusText);
                    return r.json();
                })
                .then(function(data) {
                    if (data.error) {
                        logDebug('Server Error Message: ' + data.error, true);
                    }
                    
                    state.availabilities = Array.isArray(data) ? data : (data.slots || data.availabilities || data.data || data.results || data.items || []);
                    logDebug('Received ' + state.availabilities.length + ' availability slots from API. Response structure: ' + (Array.isArray(data) ? 'Array' : Object.keys(data).join(', ')));
                    
                    var dbgContent = container.querySelector('#bk-debug-content');
                    if (dbgContent) {
                        var sample = state.availabilities.length > 0 ? (state.availabilities[0].startTimeLabel || 'Tour') + ' (' + (state.availabilities[0].startTime || state.availabilities[0].time || '') + ')' : 'None';
                        dbgContent.innerHTML = '<strong>API Status:</strong> HTTP 200 OK | <strong>Total Slots Loaded:</strong> ' + state.availabilities.length + ' slots | <strong>First Slot:</strong> ' + sample;
                    }
                    
                    if (state.availabilities.length && state.availabilities[0].activityTitle) {
                        title = state.availabilities[0].activityTitle;
                        if (DOM.sumTitle) DOM.sumTitle.textContent = title;
                    }
                    
                    if (data.pricingCategories && Array.isArray(data.pricingCategories) && data.pricingCategories.length > 0) {
                        updateCategoriesFromData(data.pricingCategories, {});
                    }
                    
                    if (state.availabilities.length > 0) {
                        const firstSlotDateStr = extractYYYYMMDD(state.availabilities[0]);
                        logDebug('Parsed date of first slot: ' + (firstSlotDateStr || 'NULL') + ' | Raw slot sample: ' + JSON.stringify(state.availabilities[0]).substring(0, 150));
                        if (firstSlotDateStr) {
                            const slotParts = firstSlotDateStr.split('-');
                            const slotYear = parseInt(slotParts[0], 10);
                            const slotMonth = parseInt(slotParts[1], 10) - 1;
                            
                            if (slotYear !== year || slotMonth !== month) {
                                logDebug('Smart Auto-Jump: Found slots in ' + (slotMonth + 1) + '/' + slotYear + '. Auto-navigating calendar!');
                                state.currentMonth = new Date(slotYear, slotMonth, 1);
                                if (DOM.calMonthLbl) DOM.calMonthLbl.textContent = state.currentMonth.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                                buildGrid(slotYear, slotMonth);
                                return;
                            }
                        }
                    }
                    
                    buildGrid(year, month);
                })
                .catch(function(err) {
                    logDebug('Fetch Error: ' + err.message, true);
                    buildGrid(year, month);
                });
            }
            
            function buildGrid(year, month) {
                if (!DOM.calGrid) return;
                DOM.calGrid.innerHTML = '';
                const firstDay = new Date(year, month, 1).getDay();
                const daysInMonth = new Date(year, month + 1, 0).getDate();
                
                let startOffset = firstDay === 0 ? 6 : firstDay - 1;
                
                for (let i = 0; i < startOffset; i++) {
                    const emptyCell = document.createElement('div');
                    DOM.calGrid.appendChild(emptyCell);
                }
                
                const today = new Date();
                today.setHours(0,0,0,0);
                let activeDaysCount = 0;
                const totalPax = state.totalParticipants || 1;
                
                for (let day = 1; day <= daysInMonth; day++) {
                    const cellDate = new Date(year, month, day);
                    const dateStr = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
                    
                    const cell = document.createElement('div');
                    cell.className = 'bk-cal-cell';
                    cell.textContent = day;
                    
                    cell.style.cssText = 'aspect-ratio:1/1 !important; width:100% !important; min-width:0 !important; height:auto !important; min-height:38px !important; display:flex !important; flex-direction:column !important; align-items:center !important; justify-content:center !important; border-radius:8px !important; position:relative !important; font-size:13px !important; font-weight:700 !important; cursor:pointer !important; box-sizing:border-box !important; margin:0 !important; padding:2px !important; overflow:hidden !important;';
                    
                    let daySlots = state.availabilities.filter(function(s) {
                        return extractYYYYMMDD(s) === dateStr;
                    });
                    
                    let openSlots = daySlots.filter(function(s) {
                        if (s.unavailable === true || s.soldOut === true) return false;
                        if (s.availabilityCount !== undefined && s.availabilityCount !== null && s.unlimitedAvailability !== true && s.availabilityCount < totalPax) return false;
                        if (s.minParticipantsToBookNow && totalPax < s.minParticipantsToBookNow) return false;
                        if (s.minParticipants && totalPax < s.minParticipants) return false;
                        if (Array.isArray(s.rates) && s.rates.length > 0) {
                            var hasEligibleRate = s.rates.some(function(r) {
                                var rMin = parseInt(r.minPerBooking || 0, 10);
                                var rMax = parseInt(r.maxPerBooking || 0, 10);
                                if (rMin > 0 && totalPax < rMin) return false;
                                if (rMax > 0 && totalPax > rMax) return false;
                                return true;
                            });
                            if (!hasEligibleRate) return false;
                        } else {
                            if (state.minPax > 0 && totalPax < state.minPax) return false;
                            if (state.maxPax > 0 && totalPax > state.maxPax) return false;
                        }
                        return true;
                    });
                    
                    if (cellDate < today || openSlots.length === 0) {
                        cell.classList.add('disabled');
                        cell.style.cssText += 'color:#cbd5e1 !important; background:transparent !important; border:1px solid transparent !important; cursor:not-allowed !important;';
                    } else {
                        activeDaysCount++;
                        cell.classList.add('available');
                        cell.style.cssText += 'color:#0f172a !important; background:#ffffff !important; border:1.5px solid #cbd5e1 !important;';
                        
                        let currentPaxTotal = 199.00;
                        if (state.categories && Array.isArray(state.categories)) {
                            let sum = 0;
                            state.categories.forEach(function(c) {
                                if (c.count > 0 && c.unitPrice > 0) sum += (c.count * c.unitPrice);
                            });
                            if (sum > 0) currentPaxTotal = sum;
                        } else if (state.adults) {
                            currentPaxTotal = 199.00 * state.adults;
                        }
                        
                        const p = document.createElement('div');
                        p.className = 'bk-price';
                        p.textContent = currentPaxTotal.toFixed(2);
                        p.style.cssText = 'font-size:9.5px !important; font-weight:700 !important; color:#800020 !important; margin-top:1px !important; line-height:1 !important; white-space:nowrap !important; max-width:100% !important; overflow:hidden !important; text-overflow:ellipsis !important;';
                        cell.appendChild(p);
                        
                        const corner = document.createElement('div');
                        corner.className = 'bk-corner-tag';
                        corner.style.cssText = 'position: absolute !important; top: 0 !important; right: 0 !important; width: 8px !important; height: 8px !important; background: ' + (state.selectedDate === dateStr ? '#ffffff' : '#22c55e') + ' !important; clip-path: polygon(100% 0, 0 0, 100% 100%) !important; pointer-events: none !important;';
                        cell.appendChild(corner);
                        
                        if (state.selectedDate === dateStr) {
                            cell.classList.add('selected');
                            cell.style.cssText += 'background:#800020 !important; color:#ffffff !important; border-color:#800020 !important; font-weight:800 !important; box-shadow:0 4px 12px rgba(128,0,32,0.3) !important;';
                            p.style.color = '#ffffff';
                        }
                        
                        (function(dStr, slots, cellEl) {
                            cellEl.addEventListener('click', function() {
                                container.querySelectorAll('.bk-cal-cell').forEach(function(c) {
                                    c.classList.remove('selected');
                                    if (c.classList.contains('available')) {
                                        c.style.cssText = 'aspect-ratio:1/1 !important; width:100% !important; min-width:0 !important; height:auto !important; min-height:38px !important; display:flex !important; flex-direction:column !important; align-items:center !important; justify-content:center !important; border-radius:8px !important; position:relative !important; font-size:13px !important; font-weight:700 !important; cursor:pointer !important; box-sizing:border-box !important; margin:0 !important; padding:2px !important; overflow:hidden !important; color:#0f172a !important; background:#ffffff !important; border:1.5px solid #cbd5e1 !important;';
                                        const pr = c.querySelector('.bk-price');
                                        if (pr) pr.style.color = '#800020';
                                    }
                                });
                                cellEl.classList.add('selected');
                                cellEl.style.cssText += 'background:#800020 !important; color:#ffffff !important; border-color:#800020 !important; font-weight:800 !important; box-shadow:0 4px 12px rgba(128,0,32,0.3) !important;';
                                const pr = cellEl.querySelector('.bk-price');
                                if (pr) pr.style.color = '#ffffff';
                                selectDate(dStr, slots);
                            });
                        })(dateStr, openSlots, cell);
                    }
                    
                    DOM.calGrid.appendChild(cell);
                }

                // If no bookable days for this passenger count or month exceeds min/max pax:
                if (activeDaysCount === 0 || (state.minPax > 0 && totalPax < state.minPax) || (state.maxPax > 0 && totalPax > state.maxPax)) {
                    DOM.calGrid.innerHTML = '<div class="bk-no-avail-month" style="grid-column: 1 / -1 !important; width: 100% !important; display: flex !important; flex-direction: column !important; align-items: center !important; justify-content: center !important; min-height: 240px !important; padding: 40px 20px !important; text-align: center !important; position: relative !important; user-select: none !important;">' +
                        '<svg width="130" height="130" viewBox="0 0 24 24" fill="none" stroke="#fecdd3" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="position: absolute !important; opacity: 0.65 !important; pointer-events: none !important;">' +
                            '<rect x="3" y="4" width="18" height="18" rx="3" ry="3"></rect>' +
                            '<line x1="16" y1="2" x2="16" y2="6"></line>' +
                            '<line x1="8" y1="2" x2="8" y2="6"></line>' +
                            '<line x1="3" y1="10" x2="21" y2="10"></line>' +
                            '<line x1="4" y1="20" x2="20" y2="4"></line>' +
                        '</svg>' +
                        '<div style="font-size: 18px !important; font-weight: 700 !important; color: #800020 !important; z-index: 2 !important; max-width: 280px !important; line-height: 1.4 !important;">' +
                            'No bookable availability is available for this month' +
                        '</div>' +
                    '</div>';
                    if (DOM.stepTimes) DOM.stepTimes.classList.add('bk-hidden');
                    if (DOM.stepOptions) DOM.stepOptions.classList.add('bk-hidden');
                    if (DOM.stepContact) DOM.stepContact.classList.add('bk-hidden');
                }
            }
            
            function selectDate(dateStr, slots) {
                state.selectedDate = dateStr;
                
                if (DOM.stepDate) DOM.stepDate.classList.add('bk-hidden');
                if (DOM.stepTime) DOM.stepTime.classList.remove('bk-hidden');
                
                const parts = dateStr.split('-');
                const d = new Date(parts[0], parts[1] - 1, parts[2]);
                if (DOM.selectedDateLbl) DOM.selectedDateLbl.textContent = d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                


                if (DOM.timeGrid) DOM.timeGrid.innerHTML = '';
                
                const validOpenSlots = (slots || []).filter(function(s) {
                    if (s.unavailable === true || s.soldOut === true) return false;
                    if (isPastCutoff(s, dateStr)) return false;
                    return true;
                });

                if (validOpenSlots.length === 0) {
                    if (DOM.timeGrid) {
                        DOM.timeGrid.innerHTML = '<div style="grid-column: 1 / -1 !important; text-align: center !important; padding: 20px !important; color: #94a3b8 !important; font-size: 14px !important; font-weight: 600 !important;">No available departure times on this date. Please select another date.</div>';
                    }
                    return;
                }
                
                const times = [];
                validOpenSlots.forEach(function(s) {
                    var tKey = s.startTime || s.time || '10:00';
                    if (!times.find(function(t) { return (t.startTime || t.time) === tKey; })) {
                        times.push(s);
                    }
                });
                
                var firstTimeBtn = null;
                times.forEach(function(slot, idx) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'bk-time-btn';
                    if (idx === 0) firstTimeBtn = btn;
                    
                                                var stId = String(slot.startTimeId || (slot.id ? (String(slot.id).split('_')[2] || String(slot.id).split('_')[0]) : ''));
                            var def = (state.startTimesMap && state.startTimesMap[stId]) ? state.startTimesMap[stId] : null;

                            var rawTime = String(slot.startTime || slot.time || slot.localizedStartTime || (def ? def.time : '') || '').trim();
                            var rawLabel = String(slot.startTimeLabel || slot.label || slot.timeLabel || slot.title || (def ? def.label : '') || '').trim();

                            if (rawTime.indexOf('T') !== -1) {
                                rawTime = rawTime.split('T')[1];
                            }

                            // 100% Dynamic 12-Hour AM/PM conversion from Bókun raw data
                            var formattedTime = '';
                            
                            var formattedTime = formatMilitaryTo12(rawTime);
                            if (!formattedTime) {
                                formattedTime = rawTime || rawLabel || 'Departure';
                            }

                            if (!formattedTime) {
                                formattedTime = rawTime || rawLabel || 'Departure';
                            }

                            // Clean rawLabel only if it repeats the exact time or is identical
                            if (rawLabel) {
                                if (rawLabel === formattedTime || rawLabel === rawTime) {
                                    rawLabel = '';
                                }
                            }
                    var displayStr = rawLabel ? (rawLabel + ' (' + formattedTime + ')') : formattedTime;
                    slot.displayTimeLabel = displayStr;
                    slot.resolvedStartTime = formattedTime;
                    
                    btn.innerHTML = '<div class="bk-time-top">' +
                        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="bk-time-clock"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>' +
                        '<span class="bk-time-value">' + formattedTime + '</span>' +
                    '</div>' +
                    (rawLabel ? '<div class="bk-time-tag">' + rawLabel + '</div>' : '');
                    
                    btn.addEventListener('click', function() {
                        container.querySelectorAll('.bk-time-btn').forEach(function(b) { b.classList.remove('selected'); });
                        btn.classList.add('selected');
                        state.selectedTime = slot;
                        state.selectedOption = null;
                        renderOptions();
                        if (DOM.stepOptions) DOM.stepOptions.classList.remove('bk-hidden');
                        if (DOM.stepContact) DOM.stepContact.classList.remove('bk-hidden');
                        updateSummary();
                    });
                    
                    if (DOM.timeGrid) DOM.timeGrid.appendChild(btn);
                });

                if (firstTimeBtn) {
                    firstTimeBtn.click();
                }
            }
            
            function renderOptions() {
                if (!DOM.optList) return;
                DOM.optList.innerHTML = '';
                
                var totalPax = 0;
                var customSum = 0;
                state.categories.forEach(function(c) {
                    if (c.count > 0) {
                        totalPax += c.count;
                        if (c.unitPrice > 0) {
                            customSum += (c.count * c.unitPrice);
                        }
                    }
                });
                if (totalPax <= 0) totalPax = 1;
                state.totalParticipants = totalPax;
                if (customSum <= 0) customSum = 199.00 * totalPax;

                let opts = [];
                
                if (state.selectedTime && Array.isArray(state.selectedTime.rates) && state.selectedTime.rates.length > 0) {
                    const slot = state.selectedTime;
                    slot.rates.forEach(function(rate, idx) {
                        var optTotalPrice = customSum;
                        var isPricedPerPerson = rate.pricedPerPerson !== false;
                        
                        if (Array.isArray(slot.pricesByRate)) {
                            const pbr = slot.pricesByRate.find(function(p) { return p.activityRateId === rate.id; });
                            if (pbr) {
                                if (Array.isArray(pbr.pricePerCategoryUnit) && pbr.pricePerCategoryUnit.length > 0) {
                                    var rateSum = 0;
                                    var matchedAny = false;
                                    state.categories.forEach(function(c) {
                                        if (c.count > 0) {
                                            var matchingUnits = pbr.pricePerCategoryUnit.filter(function(u) {
                                                return String(u.id) === String(c.id) || String(u.pricingCategoryId) === String(c.id) || String(u.categoryId) === String(c.id);
                                            });
                                            var pcu = null;
                                            if (matchingUnits.length > 0) {
                                                pcu = matchingUnits.find(function(u) {
                                                    var minP = parseInt(u.minParticipantsRequired || u.minPassengersRequired || u.minParticipants || 0, 10);
                                                    var maxP = parseInt(u.maxParticipantsRequired || u.maxPassengersRequired || u.maxParticipants || 0, 10);
                                                    var paxToCheck = totalPax > 0 ? totalPax : c.count;
                                                    return paxToCheck >= minP && (maxP <= 0 || paxToCheck <= maxP);
                                                }) || matchingUnits[matchingUnits.length - 1];
                                            }
                                            var uAmt = pcu && pcu.amount ? (typeof pcu.amount === 'object' ? pcu.amount.amount : pcu.amount) : c.unitPrice;
                                            var uVal = parseFloat(uAmt);
                                            if (!isNaN(uVal) && uVal >= 0) {
                                                rateSum += (uVal * c.count);
                                                matchedAny = true;
                                            } else {
                                                rateSum += ((c.unitPrice || 199.00) * c.count);
                                            }
                                        }
                                    });
                                    if (matchedAny && rateSum > 0) {
                                        optTotalPrice = rateSum;
                                    }
                                } else if (pbr.pricePerBooking && pbr.pricePerBooking.amount) {
                                    var pbbAmt = typeof pbr.pricePerBooking === 'object' ? pbr.pricePerBooking.amount : pbr.pricePerBooking;
                                    var valB = parseFloat(pbbAmt);
                                    if (!isNaN(valB) && valB > 0) {
                                        optTotalPrice = valB;
                                        isPricedPerPerson = false;
                                    }
                                }
                            }
                        }

                        var activeCats = state.categories.filter(function(c) { return c.count > 0; });
                        var minPax = parseInt(rate.minPerBooking || 0, 10);
                        var maxPax = parseInt(rate.maxPerBooking || 0, 10);
                        var isMinViolated = minPax > 0 && totalPax < minPax;
                        var isMaxViolated = maxPax > 0 && totalPax > maxPax;

                        var breakdownText = totalPax > 1 
                            ? ('Total for ' + totalPax + ' guests') 
                            : (currency + ' ' + (optTotalPrice / totalPax).toFixed(0) + ' / person');

                        opts.push({
                            id: rate.id,
                            title: rate.title || (idx === 0 ? 'Standard rate' : 'Premium rate'),
                            price: optTotalPrice / totalPax,
                            totalPrice: optTotalPrice,
                            minPerBooking: minPax,
                            maxPerBooking: maxPax,
                            isMinViolated: isMinViolated,
                            isMaxViolated: isMaxViolated,
                            breakdownText: breakdownText,
                            desc: 'Includes equipment, safety gear & certified instructor'
                        });
                    });
                }
                
                if (!opts.length) {
                    var activeCats = state.categories.filter(function(c) { return c.count > 0; });
                    var breakdownText = totalPax > 1 
                        ? ('Total for ' + totalPax + ' guests') 
                        : (currency + ' ' + (customSum / totalPax).toFixed(0) + ' / person');
                    opts = [
                        { id: 2577246, title: 'Standard rate', price: customSum / totalPax, totalPrice: customSum, minPerBooking: 0, maxPerBooking: 0, isMinViolated: false, isMaxViolated: false, breakdownText: breakdownText, desc: 'Includes equipment, safety gear & certified instructor' }
                    ];
                }
                
                if (DOM.optCount) DOM.optCount.textContent = opts.length;
                if (DOM.stepOptions) DOM.stepOptions.classList.remove('bk-hidden');
                
                opts.forEach(function(opt, i) {
                    var isSel = (state.selectedOption && (String(state.selectedOption.id) === String(opt.id) || state.selectedOption.name === opt.name || state.selectedOption.title === opt.title)) || (!state.selectedOption && i === 0);
                    if (isSel) {
                        state.selectedOption = opt;
                    }
                    const card = document.createElement('div');
                    card.className = 'bk-opt-card' + (isSel ? ' selected' : '');
                    card.style.cssText = 'border: ' + (isSel ? '1.5px solid #800020' : '1.5px solid #cbd5e1') + ' !important; border-radius: 12px !important; padding: 14px 16px !important; background: ' + (isSel ? '#fff5f7' : '#ffffff') + ' !important; cursor: pointer !important; display: flex !important; justify-content: space-between !important; align-items: center !important; gap: 12px !important; transition: all 0.2s ease !important; width: 100% !important; box-sizing: border-box !important; margin: 0 !important;';
                    
                    var optTotal = (opt.totalPrice !== undefined ? opt.totalPrice : (opt.price * totalPax)).toFixed(2);

                    card.innerHTML = '<div style="display:flex!important; align-items:center!important; gap:12px!important; min-width:0!important; flex-shrink:0!important;">' +
                        '<div class="bk-opt-radio" style="width:20px!important; height:20px!important; border-radius:50%!important; border:2px solid ' + (isSel ? '#800020' : '#cbd5e1') + '!important; background:' + (isSel ? '#800020' : '#ffffff') + '!important; display:flex!important; align-items:center!important; justify-content:center!important; flex-shrink:0!important;">' +
                            '<svg class="bk-opt-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" style="display:' + (isSel ? 'block' : 'none') + '!important;"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                        '</div>' +
                        '<div style="flex-shrink:0!important; display:flex!important; align-items:center!important; gap:4px!important;">' +
                            '<div style="font-weight:700!important; color:#0f172a!important; font-size:15px!important; line-height:1!important; white-space:nowrap!important;">' + opt.title + '</div>' +
                        '</div>' +
                        '</div>' +
                        '<div style="text-align:right!important; padding-left:12px!important; flex-shrink:0!important;">' +
                        '<div style="font-size:17px!important; font-weight:800!important; color:#800020!important; line-height:1!important; white-space:nowrap!important;">' + currency + ' ' + optTotal + '</div>' +
                        (totalPax > 1 ? '<div style="font-size:11px!important; color:#64748b!important; font-weight:600!important; margin-top:3px!important; white-space:nowrap!important;">Total for ' + totalPax + ' guests</div>' : '') +
                        '</div>';
                    
                    card.addEventListener('click', function() {
                        container.querySelectorAll('.bk-opt-card').forEach(function(c) {
                            c.classList.remove('selected');
                            c.style.border = '1.5px solid #cbd5e1';
                            c.style.background = '#ffffff';
                            var rad = c.querySelector('.bk-opt-radio');
                            if (rad) { rad.style.border = '2px solid #cbd5e1'; rad.style.background = '#ffffff'; }
                            var chk = c.querySelector('.bk-opt-check');
                            if (chk) chk.style.display = 'none';
                        });
                        card.classList.add('selected');
                        card.style.border = '1.5px solid #800020';
                        card.style.background = '#fff5f7';
                        var myRad = card.querySelector('.bk-opt-radio');
                        if (myRad) { myRad.style.border = '2px solid #800020'; myRad.style.background = '#800020'; }
                        var myChk = card.querySelector('.bk-opt-check');
                        if (myChk) myChk.style.display = 'block';
                        state.selectedOption = opt;
                        updateSummary();
                    });
                    
                    DOM.optList.appendChild(card);
                });

                if (state.selectedOption) {
                    updateSummary();
                }
            }
            
            function handleCheckout(e) {
                e.preventDefault();

                if (!state.selectedDate) {
                    const dateErr = 'Please select a booking date from the calendar first.';
                    if (DOM.errMsg) DOM.errMsg.textContent = dateErr;
                    alert(dateErr);
                    return;
                }

                // Validate min/max passengers per booking rule from Bókun
                if (state.selectedOption) {
                    var minPaxReq = parseInt(state.selectedOption.minPerBooking || 0, 10);
                    var maxPaxReq = parseInt(state.selectedOption.maxPerBooking || 0, 10);
                    if (minPaxReq > 0 && state.totalParticipants < minPaxReq) {
                        var minErrMsg = 'This rate option requires a minimum of ' + minPaxReq + ' participants (currently ' + state.totalParticipants + ' selected). Please increase the guest count.';
                        if (DOM.errMsg) { DOM.errMsg.textContent = minErrMsg; DOM.errMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
                        alert(minErrMsg);
                        return;
                    }
                    if (maxPaxReq > 0 && state.totalParticipants > maxPaxReq) {
                        var maxErrMsg = 'This rate option allows a maximum of ' + maxPaxReq + ' participants (currently ' + state.totalParticipants + ' selected).';
                        if (DOM.errMsg) { DOM.errMsg.textContent = maxErrMsg; DOM.errMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
                        alert(maxErrMsg);
                        return;
                    }
                }
                
                var fName = DOM.fName ? DOM.fName.value.trim() : '';
                var lName = DOM.lName ? DOM.lName.value.trim() : '';
                var email = DOM.email ? DOM.email.value.trim() : '';
                var countryCode = DOM.countryCode ? DOM.countryCode.value.trim() : '+974';
                var rawPhone = DOM.phone ? DOM.phone.value.trim() : '';
                var phoneDigits = rawPhone.replace(/[^0-9]/g, '');

                if (!fName && container) {
                    var fnInp = container.querySelector('#bk-first-name') || container.querySelector('input[name="first_name"]') || document.getElementById('bk-first-name');
                    if (fnInp && fnInp.value) fName = fnInp.value.trim();
                }
                if (!fName) {
                    var fnReqMsg = 'First name is mandatory. Please enter your first name.';
                    if (DOM.errMsg) {
                        DOM.errMsg.textContent = fnReqMsg;
                        DOM.errMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                    if (DOM.fName) {
                        DOM.fName.focus();
                        DOM.fName.style.borderColor = '#e11d48';
                    }
                    alert(fnReqMsg);
                    return;
                }
                if (DOM.fName) DOM.fName.style.borderColor = '#cbd5e1';

                if (!lName && container) {
                    var lnInp = container.querySelector('#bk-last-name') || container.querySelector('input[name="last_name"]') || document.getElementById('bk-last-name');
                    if (lnInp && lnInp.value) lName = lnInp.value.trim();
                }
                if (!lName) {
                    var lnReqMsg = 'Last name is mandatory. Please enter your last name.';
                    if (DOM.errMsg) {
                        DOM.errMsg.textContent = lnReqMsg;
                        DOM.errMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                    if (DOM.lName) {
                        DOM.lName.focus();
                        DOM.lName.style.borderColor = '#e11d48';
                    }
                    alert(lnReqMsg);
                    return;
                }
                if (DOM.lName) DOM.lName.style.borderColor = '#cbd5e1';

                if (!email && container) {
                    var emailInput = container.querySelector('#bk-email') || 
                                  container.querySelector('input[type="email"]') || 
                                  container.querySelector('input[name="email"]') || 
                                  document.getElementById('bk-email') || 
                                  document.querySelector('input[type="email"]');
                    if (emailInput && emailInput.value) {
                        email = emailInput.value.trim();
                    }
                }
                if (!email && container) {
                    var allInps = container.querySelectorAll('input');
                    for (var i = 0; i < allInps.length; i++) {
                        var val = allInps[i].value ? allInps[i].value.trim() : '';
                        if (val.indexOf('@') !== -1 && val.indexOf('.') !== -1) {
                            email = val;
                            break;
                        }
                    }
                }
                if (!email || email.indexOf('@') === -1 || email.indexOf('.') === -1) {
                    var reqMsg = 'Email address is mandatory. Please enter a valid email address so Bókun can issue your tickets.';
                    if (DOM.errMsg) {
                        DOM.errMsg.textContent = reqMsg;
                        DOM.errMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                    if (DOM.email) {
                        DOM.email.focus();
                        DOM.email.style.borderColor = '#e11d48';
                    }
                    alert(reqMsg);
                    return;
                }
                if (DOM.email) DOM.email.style.borderColor = '#cbd5e1';

                if (!rawPhone || !phoneDigits) {
                    var pReqMsg = 'Phone number is mandatory. Please enter your phone number so we can confirm your booking.';
                    if (DOM.errMsg) {
                        DOM.errMsg.textContent = pReqMsg;
                        DOM.errMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                    if (DOM.phone) {
                        DOM.phone.focus();
                        DOM.phone.style.borderColor = '#e11d48';
                    }
                    alert(pReqMsg);
                    return;
                }

                // Strict phone validation for Qatar & International
                var phone = rawPhone;
                if (countryCode === '+974' || phoneDigits.startsWith('974')) {
                    var qDigits = phoneDigits;
                    if (qDigits.startsWith('974') && qDigits.length > 8) {
                        qDigits = qDigits.substring(3);
                    }
                    if (qDigits.length !== 8) {
                        var qErrMsg = 'Qatar phone numbers must contain exactly 8 digits (e.g. 3300 1234). You entered ' + qDigits.length + ' digits.';
                        if (DOM.errMsg) {
                            DOM.errMsg.textContent = qErrMsg;
                            DOM.errMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                        if (DOM.phone) {
                            DOM.phone.focus();
                            DOM.phone.style.borderColor = '#e11d48';
                        }
                        alert(qErrMsg);
                        return;
                    }
                    phone = '+974' + qDigits;
                    phoneDigits = qDigits;
                } else if (phoneDigits.length < 7 || phoneDigits.length > 15) {
                    var intlErrMsg = 'Phone number must be between 7 and 15 digits according to international format.';
                    if (DOM.errMsg) {
                        DOM.errMsg.textContent = intlErrMsg;
                        DOM.errMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                    if (DOM.phone) {
                        DOM.phone.focus();
                        DOM.phone.style.borderColor = '#e11d48';
                    }
                    alert(intlErrMsg);
                    return;
                } else {
                    phone = rawPhone.startsWith('+') ? ('+' + phoneDigits) : (countryCode + phoneDigits);
                }

                if (DOM.phone) DOM.phone.style.borderColor = '#cbd5e1';
                
                if (DOM.errMsg) DOM.errMsg.textContent = '';
                DOM.btnCheckout.disabled = true;
                DOM.btnCheckout.innerHTML = '<div style="width:18px; height:18px; border:2px solid #fff; border-top-color:transparent; border-radius:50%; animation:bk-spin 0.8s linear infinite; display:inline-block; vertical-align:middle; margin-right:8px;"></div> Preparing checkout...';
                
                var pBookings = state.categories.filter(function(c) { return c.count > 0; }).map(function(c) {
                    return {
                        pricingCategoryId: c.id,
                        id: c.id,
                        quantity: c.count,
                        count: c.count
                    };
                });
                if (pBookings.length === 0) {
                    pBookings.push({ pricingCategoryId: 1248692, id: 1248692, quantity: Math.max(1, state.totalParticipants), count: Math.max(1, state.totalParticipants) });
                }
                
                var totalOrderAmount = 0;
                state.categories.forEach(function(c) {
                    if (c.count > 0 && c.unitPrice > 0) totalOrderAmount += (c.unitPrice * c.count);
                });
                if (totalOrderAmount <= 0) {
                    if (state.selectedOption && state.selectedOption.price) {
                        totalOrderAmount = state.selectedOption.price * Math.max(1, state.totalParticipants);
                    } else {
                        totalOrderAmount = 199.00 * Math.max(1, state.totalParticipants);
                    }
                }

                var payload = {
                    activity_id: actId,
                    activityId: actId,
                    date: state.selectedDate,
                    time: state.selectedTime ? state.selectedTime.startTime : '17:00',
                    start_time: state.selectedTime ? state.selectedTime.startTime : '17:00',
                    startTime: state.selectedTime ? state.selectedTime.startTime : '17:00',
                    start_time_id: state.selectedTime ? state.selectedTime.startTimeId : 5772342,
                    startTimeId: state.selectedTime ? state.selectedTime.startTimeId : 5772342,
                    rate_id: state.selectedOption ? state.selectedOption.id : 2577246,
                    rateId: state.selectedOption ? state.selectedOption.id : 2577246,
                    adults: state.adults,
                    totalParticipants: state.totalParticipants,
                    pricingCategories: pBookings,
                    pricingCategoryBookings: pBookings,
                    option_id: state.selectedOption ? state.selectedOption.id : 2577246,
                    amount: totalOrderAmount,
                    totalPrice: totalOrderAmount,
                    total_price: totalOrderAmount,
                    first_name: fName,
                    last_name: lName,
                    email: email,
                    emailAddress: email,
                    customer_email: email,
                    billing_email: email,
                    phone: phone,
                    phoneNumber: phone,
                    mobilePhone: phone,
                    customer: {
                        firstName: fName,
                        lastName: lName,
                        email: email,
                        emailAddress: email,
                        phoneNumber: phone,
                        phone: phone,
                        mobilePhone: phone,
                        phoneNumberCountryCode: countryCode,
                        phoneNumberBody: phoneDigits || rawPhone
                    },
                    mainContactDetails: {
                        firstName: fName,
                        lastName: lName,
                        email: email,
                        emailAddress: email,
                        phoneNumber: phone,
                        phone: phone,
                        mobilePhone: phone,
                        phoneNumberCountryCode: countryCode,
                        phoneNumberBody: phoneDigits || rawPhone
                    },
                    currency: currency
                };
                
                var url = buildEndpointUrl('reserve', '');
                logDebug('Initiating Checkout Reserve: ' + url);

                // The /reserve REST endpoint enforces a valid WP nonce via the
                // X-Bokun-Nonce header ( BokunSkipCashConfig.nonce from wp_localize_script ).
                // Without it the permission callback rejects the request with
                // "Security check failed. Please refresh the page and try again."
                var bkNonce = (window.BokunSkipCashConfig && window.BokunSkipCashConfig.nonce) ? window.BokunSkipCashConfig.nonce : '';
                if (!bkNonce) {
                    logDebug('Booking nonce missing - BokunSkipCashConfig not loaded.', true);
                }

                fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Bokun-Nonce': bkNonce
                    },
                    body: JSON.stringify(payload)
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    var targetUrl = res.payUrl || res.redirectUrl || res.paymentUrl;
                    if (res.success && targetUrl) {
                        logDebug('Reservation success! Redirecting to SkipCash: ' + targetUrl);
                        window.location.href = targetUrl;
                    } else {
                        var err = res.message || res.error || (res.details && res.details.message) || 'Could not hold seats on Bókun. Please check if the date/time is available.';
                        logDebug('Reservation Error: ' + err, true);
                        if (DOM.errMsg) DOM.errMsg.textContent = err;
                        DOM.btnCheckout.disabled = false;
                        DOM.btnCheckout.innerHTML = '<span>Proceed to Payment</span> <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>';
                    }
                })
                .catch(function(err) {
                    logDebug('Checkout fetch error: ' + err.message, true);
                    if (DOM.errMsg) DOM.errMsg.textContent = 'Connection error: ' + err.message;
                    DOM.btnCheckout.disabled = false;
                    DOM.btnCheckout.innerHTML = '<span>Proceed to Payment</span> <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>';
                });
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBokunWidgets);
    } else {
        initBokunWidgets();
    }
    
    window.addEventListener('load', initBokunWidgets);
    
    var bkCheckCount = 0;
    var bkInterval = setInterval(function() {
        bkCheckCount++;
        initBokunWidgets();
        if (bkCheckCount >= 10) clearInterval(bkInterval);
    }, 500);
})();
