<?php
// ============================================================
//  Hardcore Demonlist — Discord Webhook Watcher
//  Runs via GitHub Actions every 5 minutes.
//  Discord webhook URL is read from the DISCORD_WEBHOOK
//  environment variable (set as a GitHub Secret).
// ============================================================

declare(strict_types=1);

// ─── CONFIG ─────────────────────────────────────────────────
define('DISCORD_WEBHOOK', getenv('DISCORD_WEBHOOK') ?: '');
define('SITE_URL',        'https://completedlist.gamer.gd/demonlist');
define('CACHE_FILE',      __DIR__ . '/demon_cache.json');
define('LIST_LIMIT',      150);
// ────────────────────────────────────────────────────────────

function log_msg(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

function fetch_demons(): array {
    $all   = [];
    $after = 0;
    $limit = 50;

    while (count($all) < LIST_LIMIT) {
        $url = SITE_URL . '/api/v1/demons/?limit=' . $limit . '&after=' . $after;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err || $status !== 200) {
            log_msg("API error — HTTP $status | $err");
            break;
        }

        $demons = json_decode($body, true);
        if (empty($demons) || !is_array($demons)) break;

        foreach ($demons as $d) {
            $id = (int)($d['id'] ?? 0);
            if (!$id) continue;
            $all[$id] = [
                'id'        => $id,
                'name'      => $d['name']              ?? 'Unknown',
                'position'  => (int)($d['position']    ?? 0),
                'publisher' => $d['publisher']['name'] ?? 'Unknown',
                'video'     => $d['video']             ?? null,
            ];
        }

        if (count($demons) < $limit) break;
        $after = (int)(end($demons)['position'] ?? 0);
    }

    return $all;
}

function send_discord(array $embed): void {
    if (!DISCORD_WEBHOOK) {
        log_msg('No DISCORD_WEBHOOK set — skipping send.');
        return;
    }

    $payload = json_encode(['embeds' => [$embed]]);

    $ch = curl_init(DISCORD_WEBHOOK);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    sleep(1); // avoid Discord rate limit

    if ($code !== 204 && $code !== 200) {
        log_msg("Discord error — HTTP $code | $res");
    }
}

function embed_new(array $d): array {
    return [
        'title'       => '🆕  New Demon Added!',
        'description' => sprintf(
            "**[%s](%s)**\nby **%s**\nEntered at **#%d**",
            $d['name'],
            SITE_URL . '/' . $d['position'] . '/',
            $d['publisher'],
            $d['position']
        ),
        'color'     => 0x57F287,
        'footer'    => ['text' => 'Hardcore Demonlist Watcher'],
        'timestamp' => date('c'),
    ];
}

function embed_removed(array $d): array {
    return [
        'title'       => '❌  Demon Removed',
        'description' => sprintf(
            "**%s** (was **#%d**) by **%s** removed from the list.",
            $d['name'], $d['position'], $d['publisher']
        ),
        'color'     => 0xED4245,
        'footer'    => ['text' => 'Hardcore Demonlist Watcher'],
        'timestamp' => date('c'),
    ];
}

function embed_moved(array $old, array $new): array {
    $up    = $new['position'] < $old['position'];
    $arrow = $up ? '⬆️' : '⬇️';
    $label = $up ? 'moved UP' : 'moved DOWN';
    $color = $up ? 0x5865F2 : 0xFEE75C;

    return [
        'title'       => "$arrow  Position Change",
        'description' => sprintf(
            "**[%s](%s)**\nby **%s**\n\n**#%d → #%d** (%s)",
            $new['name'],
            SITE_URL . '/' . $new['position'] . '/',
            $new['publisher'],
            $old['position'],
            $new['position'],
            $label
        ),
        'color'     => $color,
        'footer'    => ['text' => 'Hardcore Demonlist Watcher'],
        'timestamp' => date('c'),
    ];
}

// ════════════════════════════════════════════════════════════
//  MAIN
// ════════════════════════════════════════════════════════════

log_msg('Watcher started.');

$current = fetch_demons();

if (empty($current)) {
    log_msg('No demons fetched — check site URL or API. Exiting.');
    exit(1);
}

log_msg('Fetched ' . count($current) . ' demons.');

// First run — just save snapshot
if (!file_exists(CACHE_FILE)) {
    file_put_contents(CACHE_FILE, json_encode($current, JSON_PRETTY_PRINT));
    log_msg('First run — cache created with ' . count($current) . ' demons. Watching from next run.');
    exit(0);
}

$previous = json_decode(file_get_contents(CACHE_FILE), true);
$changes  = 0;

// New demons
foreach ($current as $id => $demon) {
    if (!isset($previous[$id])) {
        log_msg("NEW: {$demon['name']} at #{$demon['position']}");
        send_discord(embed_new($demon));
        $changes++;
    }
}

// Removed demons
foreach ($previous as $id => $demon) {
    if (!isset($current[$id])) {
        log_msg("REMOVED: {$demon['name']} (was #{$demon['position']})");
        send_discord(embed_removed($demon));
        $changes++;
    }
}

// Position changes
foreach ($current as $id => $demon) {
    if (isset($previous[$id]) && $previous[$id]['position'] !== $demon['position']) {
        log_msg("MOVED: {$demon['name']} #{$previous[$id]['position']} → #{$demon['position']}");
        send_discord(embed_moved($previous[$id], $demon));
        $changes++;
    }
}

// Save updated cache
file_put_contents(CACHE_FILE, json_encode($current, JSON_PRETTY_PRINT));
log_msg($changes > 0 ? "$changes change(s) sent to Discord." : 'No changes detected.');
