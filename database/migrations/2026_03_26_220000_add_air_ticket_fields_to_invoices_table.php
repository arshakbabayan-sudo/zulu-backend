<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'vendor_locator')) {
                $table->string('vendor_locator')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'ticket_time_limit')) {
                $table->dateTime('ticket_time_limit')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'issuing_date')) {
                $table->date('issuing_date')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'net_price')) {
                $table->decimal('net_price', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'client_price')) {
                $table->decimal('client_price', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'commission_total')) {
                $table->decimal('commission_total', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'refund_amount')) {
                $table->decimal('refund_amount', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'vat_amount')) {
                $table->decimal('vat_amount', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'cancellation_without_penalty')) {
                $table->boolean('cancellation_without_penalty')->default(false);
            }
            if (! Schema::hasColumn('invoices', 'payment_type')) {
                $table->string('payment_type')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'additional_services_price')) {
                $table->decimal('additional_services_price', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'invoice_type')) {
                $table->string('invoice_type')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('invoices', 'invoice_type')) {
                $table->dropColumn('invoice_type');
            }
            if (Schema::hasColumn('invoices', 'additional_services_price')) {
                $table->dropColumn('additional_services_price');
            }
            if (Schema::hasColumn('invoices', 'payment_type')) {
                $table->dropColumn('payment_type');
            }
            if (Schema::hasColumn('invoices', 'cancellation_without_penalty')) {
                $table->dropColumn('cancellation_without_penalty');
            }
            if (Schema::hasColumn('invoices', 'vat_amount')) {
                $table->dropColumn('vat_amount');
            }
            if (Schema::hasColumn('invoices', 'refund_amount')) {
                $table->dropColumn('refund_amount');
            }
            if (Schema::hasColumn('invoices', 'commission_total')) {
                $table->dropColumn('commission_total');
            }
            if (Schema::hasColumn('invoices', 'client_price')) {
                $table->dropColumn('client_price');
            }
            if (Schema::hasColumn('invoices', 'net_price')) {
                $table->dropColumn('net_price');
            }
            if (Schema::hasColumn('invoices', 'issuing_date')) {
                $table->dropColumn('issuing_date');
            }
            if (Schema::hasColumn('invoices', 'ticket_time_limit')) {
                $table->dropColumn('ticket_time_limit');
            }
            if (Schema::hasColumn('invoices', 'vendor_locator')) {
                $table->dropColumn('vendor_locator');
            }
        });
    }
};
