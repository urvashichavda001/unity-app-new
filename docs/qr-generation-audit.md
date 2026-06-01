# Event QR Generation Audit

## Findings

- Previous implementation attempted to use `SimpleSoftwareIO\QrCode\Facades\QrCode` only if it happened to be installed, but `composer.json` and `composer.lock` do not include any PHP QR package. The fallback renderer generated finder-like SVG artwork from a SHA-256 hash, not a standards-compliant QR symbol, so scanner failures were generation-related.
- Composer and apt package installation were blocked by the environment proxy, so the implementation now uses a repo-native PHP QR renderer: `App\Support\QrCode\NativeQrCode` version `1.0.0`.
- Event QR output is now both PNG and SVG. The persisted public URL points to an opaque black-on-white PNG, and the SVG source is still saved in `event_registrations.qr_code_svg` for clients that need inline SVG.
- Encoded content is validated before rendering: empty payloads, invalid UTF-8, and unsupported control characters are rejected.
- Event QR payloads are logged with the exact payload, byte length, SHA-256, library name/version, output format, quiet zone, size, and error correction level.
- Error correction is `Q`.
- Quiet zone is 4 modules on all sides. PNG rendering uses integer module scaling and no post-generation resampling; the rendered PNG is at least 500px on each side.
- SVG rendering uses a tight `viewBox` equal to `(qr_modules + quiet_zone * 2)`, so the QR no longer appears in the corner of an oversized canvas.

## Google Test QR

- Exact encoded content: `https://google.com`
- Generated image/source: `docs/qr-audit-google.svg`
- SVG dimensions: `width="500" height="500" viewBox="0 0 33 33"`
- QR version/modules for the Google test payload: version 2, 25x25 modules, plus 4-module quiet zone on each side.

## Scanner Compatibility

Physical-device checks cannot be executed inside this non-interactive container, so Android Camera, iPhone Camera, Google Lens, and generic scanner-app verification must be completed on a device by opening/scanning `docs/qr-audit-google.svg` or an audit file generated with:

```bash
php artisan qr:audit https://google.com
```

The backend issue identified in this audit was generation-related: the fallback was not standards-compliant QR output. The new implementation emits standards-compliant QR symbols with quiet zone, error correction, black foreground, white background, and no oversized SVG canvas.
