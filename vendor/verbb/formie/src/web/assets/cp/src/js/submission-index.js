if (typeof Craft.Formie === typeof undefined) {
    Craft.Formie = {};
}

Craft.Formie.SubmissionIndex = Craft.BaseElementIndex.extend({
    init(elementType, $container, settings) {
        // this.on('selectSource', $.proxy(this, 'updateSelectedSource'));
        this.base(elementType, $container, settings);

        this.settings.criteria = {
            isIncomplete: false,
            isSpam: false,
        };

        // Find the settings menubtn, and add a new option to it
        var $menubtn = this.$statusMenuBtn.menubtn().data('menubtn');

        if ($menubtn) {
            var $incomplete = $('<li><a data-incomplete><span class="icon" data-icon="draft"></span> ' + Craft.t('formie', 'Incomplete') + '</a></li>');
            var $spam = $('<li><a data-spam><span class="icon" data-icon="error"></span> ' + Craft.t('formie', 'Spam') + '</a></li>');
            var $hr = $('<hr class="padded">');

            $menubtn.menu.addOptions($incomplete.children());
            $menubtn.menu.addOptions($spam.children());

            $hr.appendTo($menubtn.menu.$container.find('ul:first'));
            $incomplete.appendTo($menubtn.menu.$container.find('ul:first'));
            $spam.appendTo($menubtn.menu.$container.find('ul:first'));

            // Hijack the event
            $menubtn.menu.on('optionselect', $.proxy(this, '_handleStatusChange'));
        }
    },

    _handleStatusChange(ev) {
        this.statusMenu.$options.removeClass('sel');
        var $option = $(ev.selectedOption).addClass('sel');
        this.$statusMenuBtn.html($option.html());

        this.trashed = false;
        this.drafts = false;
        this.status = null;
        this.settings.criteria.isIncomplete = false;
        this.settings.criteria.isSpam = false;

        if (Garnish.hasAttr($option, 'data-spam')) {
            this.settings.criteria.isSpam = true;
        } else if (Garnish.hasAttr($option, 'data-incomplete')) {
            this.settings.criteria.isIncomplete = true;
        } else if (Garnish.hasAttr($option, 'data-trashed')) {
            this.trashed = true;
        } else if (Garnish.hasAttr($option, 'data-drafts')) {
            this.drafts = true;
        } else {
            this.status = $option.data('status');
        }

        this._updateStructureSortOption();
        this.updateElements();
    },
    
    getViewClass(mode) {
        if (mode === 'table') {
            return Craft.Formie.SubmissionTableView;
        } else {
            return this.base(mode);
        }
    },

    getDefaultSort() {
        return ['dateCreated', 'desc'];
    },
});

Craft.Formie.SubmissionTableView = Craft.TableElementIndexView.extend({
    startDate: null,
    endDate: null,

    startDatepicker: null,
    endDatepicker: null,

    $chartExplorer: null,
    $totalValue: null,
    $chartContainer: null,
    $spinner: null,
    $error: null,
    $chart: null,
    $startDate: null,
    $endDate: null,

    afterInit() {
        this.$explorerContainer = $('<div class="chart-explorer-container"></div>').prependTo(this.$container);
        this.createChartExplorer();
        this.base();
    },

    getStorage(key) {
        return Craft.Formie.SubmissionTableView.getStorage(this.elementIndex._namespace, key);
    },

    setStorage(key, value) {
        Craft.Formie.SubmissionTableView.setStorage(this.elementIndex._namespace, key, value);
    },

    createChartExplorer() {
        // chart explorer
        var $chartExplorer = $('<div class="chart-explorer"></div>').appendTo(this.$explorerContainer),
            $chartHeader = $('<div class="chart-header"></div>').appendTo($chartExplorer),
            $dateRange = $('<div class="date-range" />').appendTo($chartHeader),
            $startDateContainer = $('<div class="datewrapper"></div>').appendTo($dateRange),
            $to = $('<span class="to light">to</span>').appendTo($dateRange),
            $endDateContainer = $('<div class="datewrapper"></div>').appendTo($dateRange),
            $total = $('<div class="total"></div>').appendTo($chartHeader),
            $totalLabel = $('<div class="total-label light">' + Craft.t('formie', 'Total Submissions') + '</div>').appendTo($total),
            $totalValueWrapper = $('<div class="total-value-wrapper"></div>').appendTo($total),
            $totalValue = $('<span class="total-value">&nbsp;</span>').appendTo($totalValueWrapper);

        this.$chartExplorer = $chartExplorer;
        this.$totalValue = $totalValue;
        this.$chartContainer = $('<div class="chart-container"></div>').appendTo($chartExplorer);
        this.$spinner = $('<div class="spinner hidden" />').prependTo($chartHeader);
        this.$error = $('<div class="error"></div>').appendTo(this.$chartContainer);
        this.$chart = $('<div class="chart"></div>').appendTo(this.$chartContainer);

        this.$startDate = $('<input type="text" class="text" size="20" autocomplete="off" />').appendTo($startDateContainer);
        this.$endDate = $('<input type="text" class="text" size="20" autocomplete="off" />').appendTo($endDateContainer);

        this.$startDate.datepicker($.extend({
            onSelect: $.proxy(this, 'handleStartDateChange'),
        }, Craft.datepickerOptions));

        this.$endDate.datepicker($.extend({
            onSelect: $.proxy(this, 'handleEndDateChange'),
        }, Craft.datepickerOptions));

        this.startDatepicker = this.$startDate.data('datepicker');
        this.endDatepicker = this.$endDate.data('datepicker');

        this.addListener(this.$startDate, 'keyup', 'handleStartDateChange');
        this.addListener(this.$endDate, 'keyup', 'handleEndDateChange');

        // Set the start/end dates
        var startTime = this.getStorage('startTime') || ((new Date()).getTime() - (60 * 60 * 24 * 7 * 1000)),
            endTime = this.getStorage('endTime') || ((new Date()).getTime());

        this.setStartDate(new Date(startTime));
        this.setEndDate(new Date(endTime));

        // Load the report
        this.loadReport();
    },

    handleStartDateChange() {
        if (this.setStartDate(Craft.Formie.SubmissionTableView.getDateFromDatepickerInstance(this.startDatepicker))) {
            this.loadReport();
        }
    },

    handleEndDateChange() {
        if (this.setEndDate(Craft.Formie.SubmissionTableView.getDateFromDatepickerInstance(this.endDatepicker))) {
            this.loadReport();
        }
    },

    setStartDate(date) {
        // Make sure it has actually changed
        if (this.startDate && date.getTime() === this.startDate.getTime()) {
            return false;
        }

        this.startDate = date;
        this.setStorage('startTime', this.startDate.getTime());
        this.$startDate.val(Craft.formatDate(this.startDate));

        // If this is after the current end date, set the end date to match it
        if (this.endDate && this.startDate.getTime() > this.endDate.getTime()) {
            this.setEndDate(new Date(this.startDate.getTime()));
        }

        return true;
    },

    setEndDate(date) {
        // Make sure it has actually changed
        if (this.endDate && date.getTime() === this.endDate.getTime()) {
            return false;
        }

        this.endDate = date;
        this.setStorage('endTime', this.endDate.getTime());
        this.$endDate.val(Craft.formatDate(this.endDate));

        // If this is before the current start date, set the start date to match it
        if (this.startDate && this.endDate.getTime() < this.startDate.getTime()) {
            this.setStartDate(new Date(this.endDate.getTime()));
        }

        return true;
    },

    loadReport() {
        var requestData = this.settings.params;

        requestData.startDate = Craft.Formie.SubmissionTableView.getDateValue(this.startDate);
        requestData.endDate = Craft.Formie.SubmissionTableView.getDateValue(this.endDate);

        this.$spinner.removeClass('hidden');
        this.$error.addClass('hidden');
        this.$chart.removeClass('error');

        Craft.postActionRequest('formie/charts/get-submissions-data', requestData, $.proxy(function(response, textStatus) {
            this.$spinner.addClass('hidden');

            if (textStatus === 'success' && typeof (response.error) === 'undefined') {
                if (!this.chart) {
                    this.chart = new Craft.charts.Area(this.$chart);
                }

                var chartDataTable = new Craft.charts.DataTable(response.dataTable);

                var chartSettings = {
                    formatLocaleDefinition: response.formatLocaleDefinition,
                    orientation: response.orientation,
                    formats: response.formats,
                    dataScale: response.scale,
                };

                this.chart.draw(chartDataTable, chartSettings);

                this.$totalValue.html(response.totalHtml);
            } else {
                var msg = Craft.t('formie', 'An unknown error occurred.');

                if (typeof (response) !== 'undefined' && response && typeof (response.error) !== 'undefined') {
                    msg = response.error;
                }

                this.$error.html(msg);
                this.$error.removeClass('hidden');
                this.$chart.addClass('error');
            }
        }, this));
    },
},
{
    storage: {},

    getStorage(namespace, key) {
        if (Craft.Formie.SubmissionTableView.storage[namespace] && Craft.Formie.SubmissionTableView.storage[namespace][key]) {
            return Craft.Formie.SubmissionTableView.storage[namespace][key];
        }

        return null;
    },

    setStorage(namespace, key, value) {
        if (typeof Craft.Formie.SubmissionTableView.storage[namespace] === typeof undefined) {
            Craft.Formie.SubmissionTableView.storage[namespace] = {};
        }

        Craft.Formie.SubmissionTableView.storage[namespace][key] = value;
    },

    getDateFromDatepickerInstance(inst) {
        return new Date(inst.currentYear, inst.currentMonth, inst.currentDay);
    },

    getDateValue(date) {
        return date.getFullYear() + '-' + (date.getMonth() + 1) + '-' + date.getDate();
    },
});


Craft.registerElementIndexClass('verbb\\formie\\elements\\Submission', Craft.Formie.SubmissionIndex);
