<?php

function flights_by_date(array $flights): array
{
    $grouped = [];
    foreach ($flights as $flight) {
        $date = trim((string)($flight['flight_date'] ?? ''));
        if ($date === '') {
            continue;
        }
        $grouped[$date][] = $flight;
    }

    return $grouped;
}

function day_count_map(array $rows): array
{
    $counts = [];
    foreach ($rows as $row) {
        $counts[(int)$row['day_id']] = (int)$row['total'];
    }

    return $counts;
}

function calendar_day_date_range(array $day, array $documents, array $allDays): array
{
    $dayDate = valid_calendar_date((string)($day['day_date'] ?? '')) ?: date('Y-m-d');
    $start = $dayDate;
    $endInclusive = '';

    foreach ($documents as $document) {
        $details = decoded_document_details($document);
        if (!$details) {
            continue;
        }

        $arrival = valid_calendar_date((string)($details['arrival_date'] ?? $details['day_date'] ?? ''));
        $departure = valid_calendar_date((string)($details['departure_date'] ?? ''));
        if ($arrival !== '' && $arrival < $start) {
            $start = $arrival;
        }
        if ($departure !== '' && $departure > $endInclusive) {
            $endInclusive = $departure;
        }
    }

    if ($endInclusive === '') {
        $endInclusive = calendar_labeled_date_from_text((string)($day['details'] ?? ''), 'Departure');
    }
    $arrivalFromText = calendar_labeled_date_from_text((string)($day['details'] ?? ''), 'Arrival');
    if ($arrivalFromText !== '' && $arrivalFromText < $start) {
        $start = $arrivalFromText;
    }

    if ($endInclusive !== '' && calendar_range_crosses_different_hotel($day, $start, $endInclusive, $allDays)) {
        return calendar_range_payload($dayDate, '');
    }

    return calendar_range_payload($start, $endInclusive);
}

function calendar_range_crosses_different_hotel(array $day, string $start, string $endInclusive, array $allDays): bool
{
    $currentHotel = normalize_calendar_hotel((string)($day['hotel'] ?? ''));
    if ($currentHotel === '' || $endInclusive === '' || $endInclusive <= $start) {
        return false;
    }

    $dayDate = valid_calendar_date((string)($day['day_date'] ?? ''));
    foreach ($allDays as $candidate) {
        $candidateDate = valid_calendar_date((string)($candidate['day_date'] ?? ''));
        if ($candidateDate === '' || $candidateDate < $start || $candidateDate > $endInclusive || $candidateDate === $dayDate) {
            continue;
        }

        $candidateHotel = normalize_calendar_hotel((string)($candidate['hotel'] ?? ''));
        if ($candidateHotel !== '' && $candidateHotel !== $currentHotel) {
            return true;
        }
    }

    return false;
}

function normalize_calendar_hotel(string $hotel): string
{
    $hotel = strtolower(trim(preg_replace('/\s+/', ' ', $hotel)));
    if ($hotel === '') {
        return '';
    }

    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $hotel);
    if (is_string($converted) && $converted !== '') {
        $hotel = $converted;
    }

    return preg_replace('/[^a-z0-9]+/', '', $hotel) ?: '';
}

function calendar_flight_date_range(array $flight): array
{
    $start = valid_calendar_date((string)($flight['flight_date'] ?? '')) ?: date('Y-m-d');
    $arrivalDate = calendar_labeled_date_from_text((string)($flight['notes'] ?? ''), 'Arrival');
    if ($arrivalDate === '') {
        $departureTime = trim((string)($flight['departure_time'] ?? ''));
        $arrivalTime = trim((string)($flight['arrival_time'] ?? ''));
        if ($departureTime !== '' && $arrivalTime !== '' && $arrivalTime < $departureTime) {
            $arrivalDate = calendar_add_days($start, 1);
        }
    }

    return calendar_range_payload($start, $arrivalDate);
}

function calendar_range_payload(string $start, string $endInclusive): array
{
    if ($endInclusive === '' || $endInclusive <= $start) {
        return [
            'start' => $start,
            'endExclusive' => '',
            'label' => '',
        ];
    }

    return [
        'start' => $start,
        'endExclusive' => calendar_add_days($endInclusive, 1),
        'label' => $start . ' to ' . $endInclusive,
    ];
}

function calendar_labeled_date_from_text(string $text, string $label): string
{
    if (preg_match('/^' . preg_quote($label, '/') . ':\s*(\d{4}-\d{2}-\d{2})\b/im', $text, $matches)) {
        return valid_calendar_date($matches[1]);
    }

    return '';
}

function valid_calendar_date(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

function calendar_add_days(string $date, int $days): string
{
    return (new DateTimeImmutable($date))->modify('+' . $days . ' days')->format('Y-m-d');
}

function short_calendar_text(string $value, int $limit = 120): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value));
    if (strlen($value) <= $limit) {
        return $value;
    }

    return substr($value, 0, $limit - 3) . '...';
}

function calendar_time_from_day(array $day): string
{
    $source = trim((string)($day['transport'] ?? '') . ' ' . (string)($day['details'] ?? ''));
    if (preg_match('/\b(?:[01]?\d|2[0-3])[:.][0-5]\d\b/', $source, $matches)) {
        return str_replace('.', ':', $matches[0]);
    }
    if (preg_match('/\b(?:[1-9]|1[0-2])\s?(?:am|pm)\b/i', $source, $matches)) {
        return strtoupper(preg_replace('/\s+/', '', $matches[0]));
    }

    return '';
}

function flight_time_range(array $flight): string
{
    $departure = trim((string)($flight['departure_time'] ?? ''));
    $arrival = trim((string)($flight['arrival_time'] ?? ''));
    if ($departure !== '' && $arrival !== '') {
        return $departure . ' - ' . $arrival;
    }

    return $departure ?: $arrival;
}

function flight_title(array $flight): string
{
    return trim(implode(' ', array_filter([
        $flight['airline'] ?? '',
        $flight['flight_number'] ?? '',
    ]))) ?: 'Flight';
}

function valid_calendar_month(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}$/', $value)) {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m', $value);
    return $date && $date->format('Y-m') === $value ? $value : '';
}

function safe_pdf_filename(string $filename): string
{
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
    return trim((string)$filename, '-') ?: 'calendar.pdf';
}
