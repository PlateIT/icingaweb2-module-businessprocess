// SPDX-FileCopyrightText: 2018 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

(function(Icinga) {

    var Bp = function(module) {
        /**
         * YES, we need Icinga
         */
        this.module = module;

        this.idCache = {};

        this.initialize();

        this.module.icinga.logger.debug('BP module loaded');
    };

    Bp.prototype = {

        initialize: function()
        {
            this.module.on('click', '[data-toggle-health-urls]', function (event) {
                var button = event.currentTarget;
                var panel = button.closest('.controls').querySelector('.health-url-panel');
                panel.hidden = ! panel.hidden;
                button.setAttribute('aria-expanded', String(! panel.hidden));
                $(window).trigger('resize');
            });
            this.module.on('click', '[data-copy-health-url]', this.copyHealthUrl);
            /**
             * Tell Icinga about our event handlers
             */
            this.module.on('rendered', this.onRendered);

            this.module.on('focus', 'form input, form textarea, form select', this.formElementFocus);
            this.module.on('input', 'form input[name="name"]', this.prefillPublicHealthPath);
            this.module.on('input', '[data-public-health-path]', function (event) {
                event.currentTarget.dataset.userEdited = '1';
            });

            this.module.on('click', 'li.process summary:not(.collapsible-control)', this.processHeaderClick);
            this.module.on('end', 'ul.sortable', this.rowDropped);

            this.module.on('click', 'div.tiles > div', this.tileClick);
            this.module.on('click', '.dashboard-tile', this.dashboardTileClick);
            this.module.on('end', 'div.tiles.sortable', this.tileDropped);

            this.module.on('choose', '.sortable', this.suspendAutoRefresh);
            this.module.on('unchoose', '.sortable', this.resumeAutoRefresh);

            this.module.icinga.logger.debug('BP module initialized');
        },

        onRendered: function (event) {
            var $container = $(event.currentTarget);
            this.fixFullscreen($container);
            this.restoreCollapsedBps(event.target);
            this.highlightFormErrors($container);
            this.hideInactiveFormDescriptions($container);
            this.setupSelectorAdvanced($container);
            $container.find('[data-health-url]').each(function () {
                this.value = new URL(this.value, window.location.href).href;
            });
            $container.find('input[name="name"]').each(function () {
                Bp.prototype.prefillPublicHealthPath({currentTarget: this});
            });
            $container.find('[name="PublicApi"], [name="PublicApiAvailability"], [data-public-health-path]')
                .closest('dd').find('p.description').show();
            this.fixTileLinksOnDashboard($container);
        },

        copyHealthUrl: function (event) {
            var button = event.currentTarget;
            var input = button.parentElement.querySelector('[data-health-url]');
            input.value = new URL(input.value, window.location.href).href;
            input.focus();
            input.select();
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value).catch(function () {
                    input.focus();
                    input.select();
                });
            }
        },

        prefillPublicHealthPath: function (event) {
            var source = event.currentTarget;
            var target = source.form && source.form.querySelector('[data-public-health-path]');
            if (! target || source.readOnly || target.dataset.userEdited === '1'
                || (target.value && target.value !== target.dataset.generatedValue)) {
                return;
            }
            var value = source.value.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            target.value = value;
            target.dataset.generatedValue = value;
        },

        setupSelectorAdvanced: function ($container) {
            $container.find('form').each(function () {
                var form = this;
                var fields = Array.from(form.querySelectorAll('[data-selector-advanced]'));
                if (! fields.length || form.querySelector('[data-selector-advanced-toggle]')) {
                    return;
                }
                var rows = [];
                var expanded = false;
                fields.forEach(function (field) {
                    var row = field.closest('dd, .control-group');
                    if (! row) { return; }
                    var candidates = [row];
                    if (row.tagName === 'DD' && row.previousElementSibling
                        && row.previousElementSibling.tagName === 'DT') {
                        candidates.unshift(row.previousElementSibling);
                    }
                    candidates.forEach(function (candidate) {
                        if (rows.indexOf(candidate) < 0) { rows.push(candidate); }
                    });
                    var value = field.multiple
                        ? Array.from(field.selectedOptions).map(function (option) { return option.value; }).join(',')
                        : field.value;
                    if (! field.hasAttribute('data-selector-ignore-value')
                        && value && value !== (field.getAttribute('data-default-value') || '')) {
                        expanded = true;
                    }
                    if (field.getAttribute('aria-invalid') === 'true' || candidates.some(function (candidate) {
                        return ['errors', 'error', 'has-error'].some(function (name) { return candidate.classList.contains(name); });
                    })) {
                        expanded = true;
                    }
                });
                if (! rows.length) { return; }
                var holder = document.createElement(rows[0].tagName === 'DT' ? 'dd' : 'div');
                holder.className = 'control-group';
                var button = document.createElement('button');
                button.type = 'button';
                button.textContent = 'Advanced settings';
                button.setAttribute('data-selector-advanced-toggle', '1');
                holder.appendChild(button);
                if (rows[0].tagName === 'DT') {
                    rows[0].before(document.createElement('dt'));
                }
                rows[0].before(holder);
                function update() {
                    button.setAttribute('aria-expanded', String(expanded));
                    rows.forEach(function (row) {
                        row.hidden = ! expanded;
                        row.style.display = expanded ? '' : 'none';
                    });
                }
                button.addEventListener('click', function () { expanded = ! expanded; update(); });
                update();
            });
        },

        // TODO: Remove once support for Icinga Web 2.10.x is dropped
        processHeaderClick: function (event) {
            event.stopPropagation();
            event.preventDefault();

            let details = event.currentTarget.parentNode;
            details.open = ! details.open;

            let bpUl = event.currentTarget.closest('.content > ul.bp');
            if (! bpUl || ! ('isRootConfig' in bpUl.dataset)) {
                return;
            }

            let bpName = bpUl.id;
            if (typeof this.idCache[bpName] === 'undefined') {
                this.idCache[bpName] = [];
            }

            let li = details.parentNode;
            let index = this.idCache[bpName].indexOf(li.id);
            if (! details.open) {
                if (index === -1) {
                    this.idCache[bpName].push(li.id);
                }
            } else if (index !== -1) {
                this.idCache[bpName].splice(index, 1);
            }
        },

        hideInactiveFormDescriptions: function($container) {
            $container.find('dd').not('.active').find('p.description').hide();
        },

        tileClick: function(event) {
            $(event.currentTarget).find('> a').first().trigger('click');
        },

        dashboardTileClick: function(event) {
            $(event.currentTarget).find('> .bp-link > a').first().trigger('click');
        },

        suspendAutoRefresh: function(event) {
            // TODO: If there is a better approach some time, let me know
            $(event.originalEvent.from).closest('.container').data('lastUpdate', (new Date()).getTime() + 3600 * 1000);
            event.stopPropagation();
        },

        resumeAutoRefresh: function(event) {
            var $container = $(event.originalEvent.from).closest('.container');
            $container.data('lastUpdate', (new Date()).getTime() - ($container.data('icingaRefresh') || 10) * 1000);
            event.stopPropagation();
        },

        tileDropped: function(event) {
            var evt = event.originalEvent;
            if (evt.oldIndex !== evt.newIndex) {
                var $source = $(evt.from);
                $source.addClass('progress')
                    .data('sortable').option('disabled', true);

                var data = {
                    csrfToken: $source.data('csrfToken'),
                    movenode: 'movenode', // That's the submit button..
                    parent: $(evt.to).data('nodeName') || '',
                    from: evt.oldIndex,
                    to: evt.newIndex
                };

                var actionUrl = [
                    $source.data('actionUrl'),
                    'action=move',
                    'movenode=' + $(evt.item).data('nodeName')
                ].join('&');

                var $container = $source.closest('.container');
                var icingaLoader = this.module.icinga.loader;
                icingaLoader.loadUrl(actionUrl, $container, data, 'POST')
                    .done((_, __, req) => icingaLoader.processNotificationHeader(req));
            }
        },

        rowDropped: function(event) {
            var evt = event.originalEvent,
                $source = $(evt.from),
                $target = $(evt.to);

            if (evt.oldIndex !== evt.newIndex || !$target.is($source)) {
                var $root = $target.closest('.content > ul.bp');
                $root.addClass('progress')
                    .find('ul.bp')
                    .add($root)
                    .each(function() {
                        $(this).data('sortable').option('disabled', true);
                    });

                var data = {
                    csrfToken: $target.data('csrfToken'),
                    movenode: 'movenode', // That's the submit button..
                    parent: $target.closest('.process').data('nodeName') || '',
                    from: evt.oldIndex,
                    to: evt.newIndex
                };

                var actionUrl = [
                    $source.data('actionUrl'),
                    'action=move',
                    'movenode=' + $(evt.item).data('nodeName')
                ].join('&');

                var $container = $target.closest('.container');
                var icingaLoader = this.module.icinga.loader;
                icingaLoader.loadUrl(actionUrl, $container, data, 'POST')
                    .done((_, __, req) => icingaLoader.processNotificationHeader(req));

                event.stopPropagation();
            }
        },

        /**
         * Called by Sortable.js while in Tree-View
         *
         * See group option on the sortable elements.
         *
         * @param to
         * @param from
         * @param item
         * @param event
         * @returns boolean
         */
        rowPutAllowed: function(to, from, item, event) {
            if (to.options.group.name === 'root') {
                return $(item).is('.process');
            }

            // Otherwise we're facing a nesting error next
            var $item = $(item),
                childrenNames = $item.find('.process').map(function () {
                    return $(this).data('nodeName');
                }).get();
            childrenNames.push($item.data('nodeName'));
            var loopDetected = $(to.el).parents('.process').toArray().some(function (parent) {
                return childrenNames.indexOf($(parent).data('nodeName')) !== -1;
            });

            return !loopDetected;
        },

        fixTileLinksOnDashboard: function($container) {
            if ($container.closest('div.dashboard').length) {
                $container.find('div.tiles').data('baseTarget', '_next');
            }
        },

        fixFullscreen: function($container) {
            var $controls = $container.find('div.controls');
            var $layout = $('#layout');
            var icinga = this.module.icinga;
            if ($controls.hasClass('want-fullscreen')) {
                if (!$layout.hasClass('fullscreen-layout')) {
                    $layout.addClass('fullscreen-layout');
                    icinga.ui.currentLayout = 'fullscreen';
                }
            } else if (! $container.parent('.dashboard').length) {
                if ($layout.hasClass('fullscreen-layout')) {
                    $layout.removeClass('fullscreen-layout');
                    icinga.ui.layoutHasBeenChanged();
                }
            }
        },

        // TODO: Remove once support for Icinga Web 2.10.x is dropped
        restoreCollapsedBps: function(container) {
            let bpUl = container.querySelector('.content > ul.bp');
            if (! bpUl || ! ('isRootConfig' in bpUl.dataset)) {
                return;
            }

            let bpName = bpUl.id;
            if (typeof this.idCache[bpName] === 'undefined') {
                return;
            }

            bpUl.querySelectorAll('li.process').forEach(li => {
                if (this.idCache[bpName].indexOf(li.id) !== -1) {
                    li.querySelector(':scope > details').open = false;
                }
            });
        },

        /** BEGIN Form handling, borrowed from Director **/
        formElementFocus: function(ev)
        {
            var $input = $(ev.currentTarget);
            var $dd = $input.closest('dd');
            $dd.find('p.description').show();
            if ($dd.attr('id') && $dd.attr('id').match(/button/)) {
                return;
            }
            var $li = $input.closest('li');
            var $dt = $dd.prev();
            var $form = $dd.closest('form');

            $form.find('dt, dd, li').removeClass('active');
            $li.addClass('active');
            $dt.addClass('active');
            $dd.addClass('active');
            $dd.find('p.description.fading-out')
                .stop(true)
                .removeClass('fading-out')
                .fadeIn('fast');

            $form.find('dd').not($dd)
                .find('p.description')
                .not('.fading-out')
                .addClass('fading-out')
                .delay(2000)
                .fadeOut('slow', function() {
                    $(this).removeClass('fading-out').hide()
                });
        },

        highlightFormErrors: function($container)
        {
            $container.find('dd ul.errors').each(function(idx, ul) {
                var $ul = $(ul);
                var $dd = $ul.closest('dd');
                var $dt = $dd.prev();

                $dt.addClass('errors');
                $dd.addClass('errors');
            });
        }
        /** END Form handling **/
    };

    Icinga.availableModules.businessprocess = Bp;

}(Icinga));

