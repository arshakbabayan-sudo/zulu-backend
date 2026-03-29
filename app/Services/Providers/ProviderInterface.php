<?php

namespace App\Services\Providers;

interface ProviderInterface
{
    /**
     * Search for available services (Flights, Hotels, etc.)
     */
    public function search(array $params): array;

    /**
     * Get details for a specific offer from the provider.
     */
    public function getOfferDetails(string $providerOfferId): array;

    /**
     * Book the service with the provider.
     */
    public function book(array $bookingData): array;

    /**
     * Cancel the booking with the provider.
     */
    public function cancel(string $providerBookingId): bool;
}
