/** @lends EntitySelectSearchApiAccessor */
define(function(require) {
    'use strict';

    /**
     * Provides access to entity_select search API.
     *
     * @class
     * @augment SearchApiAccessor
     * @exports EntitySelectSearchApiAccessor
     */
    var EntitySelectSearchApiAccessor;
    var SearchApiAccessor = require('oroui/js/tools/search-api-accessor');

    EntitySelectSearchApiAccessor = SearchApiAccessor.extend(/** @exports EntitySelectSearchApiAccessor.prototype */{
        /**
         * @constructor
         * @param options {object}
         * @param options.entity_name {string} entity name to search in
         * @param options.field_name {string} field to search by and to show in UI
         */
        initialize: function(options) {
            if (!options) {
                options = {};
            }
            options.search_handler_name = 'entity_select';
            if (!options.entity_name || !options.field_name) {
                throw new Error('`entity_name` and `field_name` options are required');
            }
            this.entityName = options.entity_name;
            this.fieldName = options.field_name;
            options.label_field_name = options.field_name;
            EntitySelectSearchApiAccessor.__super__.initialize.call(this, options);
        },

        /**
         * @inheritDoc
         */
        prepareUrlParameters: function(urlParameters) {
            urlParameters.query = [urlParameters.term, this.entityName, this.fieldName].join(',');
            urlParameters.name = this.searchHandlerName;
            return urlParameters;
        }
    });

    return EntitySelectSearchApiAccessor;
});
