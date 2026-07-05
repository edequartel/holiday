# Holiday Planner PHP + Tabler

A small uploadable PHP/MySQL holiday dashboard with:

- Trips
- Flights
- Day-by-day itinerary
- PDF booking imports into itinerary days
- Packing checklist
- Leaflet map
- Hotels, parking, points of interest, restaurants and transport points
- OpenAI POI suggestion endpoint

## Install

1. Upload the app files to your hosting folder, for example `/public_html/holiday-planner/`.
2. Create a MySQL database in your hosting panel.
3. Import `schema.sql` in phpMyAdmin.
4. Copy `secrets.example.php` to a private folder outside `public_html`, for example `/home/YOUR_ACCOUNT/private/holiday-secrets.php`.
5. Edit that private `holiday-secrets.php` with your database credentials and OpenAI API key.
6. Open `index.php` in your browser.

## Security

Do not put a real API key in GitHub or in a public web folder. The app automatically checks these private locations first:

- `/home/YOUR_ACCOUNT/private/holiday-secrets.php`
- `/home/YOUR_ACCOUNT/private/secrets.php`
- `/home/YOUR_ACCOUNT/holiday-private/secrets.php`

For local development only, you can keep an untracked `secrets.php` beside `index.php`. It is ignored by Git and blocked by `.htaccess`, but the safer Bluehost setup is still to keep the real file outside `public_html`.

## OpenAI

The POI suggestions and PDF itinerary imports use the OpenAI Responses API through cURL. The current OpenAI quickstart shows API keys should be stored securely and the Responses API is used for text generation.

Uploaded booking PDFs are stored in `uploads/day-documents/`, ignored by Git, and opened through `document.php`.
