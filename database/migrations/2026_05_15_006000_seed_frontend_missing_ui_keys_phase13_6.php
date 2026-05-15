<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13.6 batch 4 — seed every ui_translations key that the public
 * frontend (zulu-frontend-next) references via t() but for which prod
 * had no DB row yet. Until this commit, those keys fell back to the
 * English defaults baked into `zulu-frontend-next/lib/lang.ts`, which
 * is why HY/RU readers saw English text on the home search form, the
 * newsletter band, AI search, vouchers, loyalty, payment, insurance,
 * visa, auth flows, and several others.
 *
 * This migration inserts ONLY the EN value (sourced verbatim from the
 * frontend's own defaultUiTranslations table). Two follow-ups land
 * the HY + RU translations:
 *
 *   1. `php artisan translations:scan --ui` on prod queues a
 *      TranslateUiStringJob for every key that has EN but no HY/RU
 *      row — the Phase 13.5 Claude worker fills the gap.
 *   2. Eight new newsletter.* keys come from the
 *      HomeBottomNewsletter refactor that lands in the same admin /
 *      frontend commit pair.
 *
 * 208 keys × 1 language = 208 rows inserted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            // Newsletter (bottom Subscribe section refactored to t())
            ['newsletter.heading_lead', 'Subscribe'],
            ['newsletter.heading_tail', 'to our newsletter'],
            ['newsletter.body', 'Be the first to receive exclusive offers and the latest news on our services directly in your inbox.'],
            ['newsletter.btn_subscribe', 'Subscribe'],
            ['newsletter.unsubscribe_hint', '*You can easily unsubscribe any time'],
            ['newsletter.err_enter_email', 'Please enter an email'],
            ['newsletter.msg_already', "You're already on the list."],
            ['newsletter.msg_subscribed', 'Subscribed — welcome!'],

            // Auto-generated from zulu-frontend-next/lib/lang.ts defaults
            ['ai.search.button_long', 'Search with AI'],
            ['ai.search.button_short', 'AI Search'],
            ['ai.search.example_1', 'Hotel in Yerevan with breakfast, under 150$, July'],
            ['ai.search.examples_label', 'Try:'],
            ['ai.search.failed', 'AI search failed. Try again.'],
            ['ai.search.min_chars', 'Please enter at least 3 characters.'],
            ['ai.search.placeholder', 'e.g. Hotel in Yerevan with pool, under $200, December 15-20 for 2 adults'],
            ['ai.search.redirecting', 'Redirecting to results…'],
            ['ai.search.search_button', 'Search'],
            ['ai.search.subtitle', "Describe what you're looking for in plain language. AI will understand and find it."],
            ['ai.search.thinking', 'Thinking…'],
            ['ai.search.title', 'AI Search'],
            ['ai.search.understood', 'Got it!'],
            ['auth.generate_save', 'Save'],
            ['auth.generate_title', 'Generate password'],
            ['auth.register_privacy_link', 'Privacy statement'],
            ['auth.register_terms_and', 'and'],
            ['auth.register_terms_link', 'Terms & conditions'],
            ['auth.register_terms_prefix', 'By signing in or creating an account, you agree with our'],
            ['auth.reset_back_login', 'Back to Log in'],
            ['auth.reset_confirm_label', 'Confirm new password'],
            ['auth.reset_confirm_placeholder', 'Repeat your new password'],
            ['auth.reset_done_body', 'Your password has been reset successfully. Redirecting you to login…'],
            ['auth.reset_done_title', 'Password updated!'],
            ['auth.reset_email_label', 'Email address'],
            ['auth.reset_email_placeholder', 'you@example.com'],
            ['auth.reset_failed', 'Reset failed'],
            ['auth.reset_generic_error', 'Something went wrong'],
            ['auth.reset_go_login', 'Go to login now'],
            ['auth.reset_invalid_body', 'This reset link is invalid or has expired. Please request a new one.'],
            ['auth.reset_invalid_title', 'Invalid link'],
            ['auth.reset_new_password_label', 'New password'],
            ['auth.reset_new_password_placeholder', 'At least 8 characters'],
            ['auth.reset_password_too_short', 'Password must be at least 8 characters.'],
            ['auth.reset_passwords_match', 'Passwords match'],
            ['auth.reset_passwords_mismatch', 'Passwords do not match'],
            ['auth.reset_passwords_mismatch_period', 'Passwords do not match.'],
            ['auth.reset_request_new', 'Request new link'],
            ['auth.reset_save', 'Reset'],
            ['auth.reset_saving', 'Saving…'],
            ['auth.reset_title', 'Reset your password'],
            ['auth.tfa_back_login', 'Back to Log in'],
            ['auth.tfa_didnt_get', "Didn't get the code?"],
            ['auth.tfa_digit_aria', 'Digit'],
            ['auth.tfa_enter_all_digits', 'Please enter all 6 digits.'],
            ['auth.tfa_failed', 'Verification failed'],
            ['auth.tfa_resend', 'Resend code'],
            ['auth.tfa_resend_failed', 'Could not resend. Please try again.'],
            ['auth.tfa_resending', 'Resending…'],
            ['auth.tfa_resent', 'Code resent.'],
            ['auth.tfa_subtitle_prefix', 'Check your email ('],
            ['auth.tfa_subtitle_suffix', ') and input the verification code below.'],
            ['auth.tfa_title', 'Enter your code'],
            ['auth.tfa_verify', 'Verify'],
            ['auth.tfa_verifying', 'Verifying…'],
            ['common.confirm', 'Confirm'],
            ['common.done', 'Done'],
            ['common.female', 'Female'],
            ['common.male', 'Male'],
            ['common.processing', 'Processing…'],
            ['excursion.card.free_cancelation', 'Free cancelation'],
            ['excursion.card.from', 'from'],
            ['excursion.card.hour', 'hour'],
            ['excursion.card.hours', 'hours'],
            ['excursion.card.minutes', 'minutes'],
            ['excursion.card.price_varies', 'Price varies by group size'],
            ['excursion.card.view_more', 'View More'],
            ['excursion.categories.thing_to_do', 'things to do'],
            ['excursion.filter.alliance', 'Alliance'],
            ['excursion.filter.duration', 'Duration'],
            ['excursion.filter.price', 'Price'],
            ['excursion.filter.specials', 'Specials'],
            ['excursion.filter.star_none', 'without star rating'],
            ['excursion.filter.time_of_day', 'Time of day'],
            ['home.search.adults_age', 'Adults (12+ years)'],
            ['home.search.child_age', 'Child (2–11 years)'],
            ['home.search.children_age_stays', 'Children (2 - 11 years)'],
            ['home.search.depart_short', 'Depart'],
            ['home.search.end_date', 'End date'],
            ['home.search.guests', 'Guests'],
            ['home.search.hot_deals', 'Hot deals'],
            ['home.search.infant_age', 'Infant (under 2 years)'],
            ['home.search.label_to', 'To'],
            ['home.search.nationality', 'Nationality'],
            ['home.search.passenger_label_many', '{n} Passengers'],
            ['home.search.passenger_label_one', '1 Passenger'],
            ['home.search.passengers', 'Passengers'],
            ['home.search.pax_nationality_heading', 'Pax Nationality'],
            ['home.search.placeholder.destination_city_airport', 'Destination city or airport'],
            ['home.search.placeholder.enter_location', 'Enter a location'],
            ['home.search.placeholder.hotel_destination', 'Enter a city, hotel, airport, address or landmark'],
            ['home.search.placeholder.nationality', 'Enter your nationality'],
            ['home.search.placeholder.origin_city_airport', 'Origin city or airport'],
            ['home.search.popup.class_section', 'Class'],
            ['home.search.popup.passengers_section', 'Passengers'],
            ['home.search.price', 'Price'],
            ['home.search.return_different_location', 'Return to a different location'],
            ['home.search.return_short', 'Return'],
            ['home.search.room_label', 'Room'],
            ['home.search.start_date', 'Start date'],
            ['home.search.time', 'Time'],
            ['home.search.trip.aria_group', 'Trip type'],
            ['home.search.word_room_plural', 'Rooms'],
            ['home.search.word_room_singular', 'Room'],
            ['insurance.company', 'Insurance company'],
            ['insurance.continue_to_payment', 'Continue to payment'],
            ['insurance.country', 'Country'],
            ['insurance.coverage', 'Insurance coverage'],
            ['insurance.days', 'Days'],
            ['insurance.email', 'Email'],
            ['insurance.end_date', 'End date'],
            ['insurance.files', 'Files'],
            ['insurance.first_name', 'First name'],
            ['insurance.gender', 'Gender'],
            ['insurance.last_name', 'Last name'],
            ['insurance.nationality', 'Nationality'],
            ['insurance.passport_authority', 'Authority'],
            ['insurance.passport_details', 'Passport details'],
            ['insurance.passport_issue_date', 'Date of issue'],
            ['insurance.passport_number', 'Passport number'],
            ['insurance.passport_valid_until', 'Valid until'],
            ['insurance.personal_info', 'Personal information'],
            ['insurance.phone', 'Phone number'],
            ['insurance.place_of_birth', 'Place of birth'],
            ['insurance.start_date', 'Start date'],
            ['insurance.title', 'Add insurance package'],
            ['insurance.type', 'Insurance type'],
            ['insurance.type_accident', 'Accident'],
            ['insurance.type_cancellation', 'Trip cancellation'],
            ['insurance.type_health', 'Health'],
            ['loyalty.balance_label', 'Points balance'],
            ['loyalty.help_text', 'Points can be redeemed at checkout (up to 20% of order total). 1 point = $0.01 USD.'],
            ['loyalty.history_empty', 'No loyalty activity yet. Make your first booking to start earning!'],
            ['loyalty.history_title', 'Recent activity'],
            ['loyalty.lifetime_value', 'Available now'],
            ['loyalty.load_failed', 'Could not load loyalty data.'],
            ['loyalty.next_tier', 'Progress to'],
            ['loyalty.points', 'points'],
            ['loyalty.subtitle', 'Earn points on every booking, redeem for discounts.'],
            ['loyalty.tier_label', 'Your tier'],
            ['loyalty.tier_multiplier', 'Earn rate:'],
            ['loyalty.title', 'Loyalty rewards'],
            ['loyalty.total_earned', 'Total earned'],
            ['loyalty.total_redeemed', 'Total redeemed'],
            ['payment.add_card.add', 'Add'],
            ['payment.add_card.address', 'Address line'],
            ['payment.add_card.card_number', 'Card number'],
            ['payment.add_card.city', 'City'],
            ['payment.add_card.country', 'Country'],
            ['payment.add_card.expiration', 'Expiration date'],
            ['payment.add_card.name_on_card', 'Name on card'],
            ['payment.add_card.postal_code', 'Postal code'],
            ['payment.add_card.security_notice', 'Card data is captured via PCI-compliant tokenizer (Stripe / ArCa / Idram). Real charging happens on the next step after gateway activation.'],
            ['payment.add_card.state_region', 'State / Region'],
            ['payment.add_card.subtitle', 'Securely save a card for faster checkouts.'],
            ['payment.add_card.title', 'Add a credit card'],
            ['payment.pending.back_home', 'Back to home'],
            ['payment.pending.body', 'Your information was received. Payment processing will be activated shortly.'],
            ['payment.pending.notice_body', "Payment gateway integration (Stripe / ArCa / Idram) is being finalized. You'll receive an email when your booking is confirmed."],
            ['payment.pending.notice_title', 'Notice'],
            ['payment.pending.redirect_notice', 'Redirecting you to home in 8 seconds…'],
            ['payment.pending.reference', 'Reference'],
            ['payment.pending.title', "We're getting payment ready"],
            ['pwa.install.body', 'Add ZULU to your home screen for faster access and offline support.'],
            ['pwa.install.cta', 'Install'],
            ['pwa.install.dismiss', 'Not now'],
            ['pwa.install.title', 'Install ZULU app'],
            ['visa.continue_to_payment', 'Continue to payment'],
            ['visa.country_destination', 'Destination country'],
            ['visa.depart', 'Depart'],
            ['visa.email', 'Email'],
            ['visa.files', 'Files'],
            ['visa.first_name', 'First name'],
            ['visa.gender', 'Gender'],
            ['visa.last_name', 'Last name'],
            ['visa.nationality', 'Nationality'],
            ['visa.passport_authority', 'Authority'],
            ['visa.passport_details', 'Passport details'],
            ['visa.passport_issue_date', 'Date of issue'],
            ['visa.passport_number', 'Passport number'],
            ['visa.passport_valid_until', 'Valid until'],
            ['visa.personal_info', 'Personal information'],
            ['visa.place_of_birth', 'Place of birth'],
            ['visa.return', 'Return'],
            ['visa.title', 'Online visa application'],
            ['visa.travel', 'Travel details'],
            ['visa.type', 'Visa type'],
            ['visa.type_multi', 'Multi-entry'],
            ['visa.type_single', 'Single-entry'],
            ['visa.type_tourist', 'Tourist'],
            ['vouchers.download', 'Download PDF'],
            ['vouchers.empty_hint', 'Vouchers are issued automatically when your booking is paid.'],
            ['vouchers.empty_title', 'No vouchers yet'],
            ['vouchers.load_failed', 'Could not load your vouchers.'],
            ['vouchers.resend', 'Resend email'],
            ['vouchers.resend_failed', 'Could not resend voucher email.'],
            ['vouchers.resend_success', 'Voucher email sent.'],
            ['vouchers.resending', 'Sending…'],
            ['vouchers.subtitle', 'Travel vouchers issued for your bookings — download, resend, scan QR.'],
            ['vouchers.title', 'My vouchers'],
        ];

        $batch = [];
        foreach ($rows as $r) {
            [$key, $en] = $r;
            $batch[] = ['language_code' => 'en', 'key' => $key, 'value' => $en, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($batch, 200) as $chunk) {
            DB::table('ui_translations')->upsert(
                $chunk,
                ['language_code', 'key'],
                ['value', 'updated_at']
            );
        }

        Cache::forget('ui_translations_en');
    }

    public function down(): void
    {
        // No down() — keys may have been further translated by the AI
        // scan; rolling back would orphan HY/RU rows.
    }
};
