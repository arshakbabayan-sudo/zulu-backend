<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $addIndex = function (string $table, array|string $columns, string $indexName): void {
            if (! Schema::hasTable($table)) {
                return;
            }

            $cols = is_array($columns) ? $columns : [$columns];
            foreach ($cols as $col) {
                if (! Schema::hasColumn($table, $col)) {
                    return;
                }
            }

            if (Schema::hasIndex($table, $indexName)) {
                return;
            }

            Schema::table($table, function (Blueprint $t) use ($columns, $indexName): void {
                $t->index($columns, $indexName);
            });
        };

        // companies
        $addIndex('companies', 'governance_status', 'companies_governance_status_index');
        $addIndex('companies', 'is_seller', 'companies_is_seller_index');
        $addIndex('companies', 'status', 'companies_status_index');
        $addIndex('companies', 'type', 'companies_type_index');

        // offers
        $addIndex('offers', 'status', 'offers_status_index');
        $addIndex('offers', 'type', 'offers_type_index');
        $addIndex('offers', ['company_id', 'status'], 'offers_company_id_status_index');

        // user_company
        $addIndex('user_company', 'role_id', 'user_company_role_id_index');

        // bookings
        $addIndex('bookings', 'status', 'bookings_status_index');

        // package_orders
        $addIndex('package_orders', 'status', 'package_orders_status_index');

        // payments
        $addIndex('payments', 'status', 'payments_status_index');

        // commission_records
        $addIndex('commission_records', 'status', 'commission_records_status_index');

        // transfers
        $addIndex('transfers', ['company_id', 'status'], 'transfers_company_id_status_index');
        $addIndex('transfers', 'status', 'transfers_status_index');
        $addIndex('transfers', 'availability_status', 'transfers_availability_status_index');
        $addIndex('transfers', 'transfer_type', 'transfers_transfer_type_index');
        $addIndex('transfers', 'vehicle_category', 'transfers_vehicle_category_index');

        // flights
        $addIndex('flights', ['company_id', 'status'], 'flights_company_id_status_index');

        // hotels
        $addIndex('hotels', ['company_id', 'status'], 'hotels_company_id_status_index');

        // cars
        $addIndex('cars', ['company_id', 'status'], 'cars_company_id_status_index');
        $addIndex('cars', 'status', 'cars_status_index');
        $addIndex('cars', 'availability_status', 'cars_availability_status_index');
        $addIndex('cars', 'vehicle_class', 'cars_vehicle_class_index');

        // excursions
        $addIndex('excursions', ['company_id', 'status'], 'excursions_company_id_status_index');
        $addIndex('excursions', 'status', 'excursions_status_index');
        $addIndex('excursions', 'offer_id', 'excursions_offer_id_index');
    }

    public function down(): void
    {
        $dropIndex = function (string $table, string $indexName): void {
            if (! Schema::hasTable($table)) {
                return;
            }

            if (! Schema::hasIndex($table, $indexName)) {
                return;
            }

            Schema::table($table, function (Blueprint $t) use ($indexName): void {
                $t->dropIndex($indexName);
            });
        };

        $dropIndex('companies', 'companies_governance_status_index');
        $dropIndex('companies', 'companies_is_seller_index');
        $dropIndex('companies', 'companies_status_index');
        $dropIndex('companies', 'companies_type_index');

        $dropIndex('offers', 'offers_status_index');
        $dropIndex('offers', 'offers_type_index');
        $dropIndex('offers', 'offers_company_id_status_index');

        $dropIndex('user_company', 'user_company_role_id_index');

        $dropIndex('bookings', 'bookings_status_index');
        $dropIndex('package_orders', 'package_orders_status_index');
        $dropIndex('payments', 'payments_status_index');
        $dropIndex('commission_records', 'commission_records_status_index');

        $dropIndex('transfers', 'transfers_company_id_status_index');
        $dropIndex('transfers', 'transfers_status_index');
        $dropIndex('transfers', 'transfers_availability_status_index');
        $dropIndex('transfers', 'transfers_transfer_type_index');
        $dropIndex('transfers', 'transfers_vehicle_category_index');

        $dropIndex('flights', 'flights_company_id_status_index');
        $dropIndex('hotels', 'hotels_company_id_status_index');

        $dropIndex('cars', 'cars_company_id_status_index');
        $dropIndex('cars', 'cars_status_index');
        $dropIndex('cars', 'cars_availability_status_index');
        $dropIndex('cars', 'cars_vehicle_class_index');

        $dropIndex('excursions', 'excursions_company_id_status_index');
        $dropIndex('excursions', 'excursions_status_index');
        $dropIndex('excursions', 'excursions_offer_id_index');
    }
};
