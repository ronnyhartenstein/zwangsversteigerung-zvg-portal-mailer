<?php

require __DIR__.'/vendor/autoload.php';

use GuzzleHttp\Client;

$base_uri = 'http://www.zvg-portal.de';

$client = new Client([
    // Base URI is used with relative requests
    'base_uri' => $base_uri,
    // You can set any number of default request options.
    'timeout'  => 5.0,
]);

/**
 * Liste
 */
/*
curl 'http://www.zvg-portal.de/index.php?button=Suchen&all=1' \
    -H 'Origin: http://www.zvg-portal.de' \
    -H 'Accept-Encoding: gzip, deflate' \
    -H 'Accept-Language: de-DE,de;q=0.8,en-US;q=0.6,en;q=0.4' \
    -H 'Upgrade-Insecure-Requests: 1' \
    -H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/56.0.2924.87 Safari/537.36' \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    -H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,* /*;q=0.8' \
    -H 'Cache-Control: max-age=0' \
    -H 'Referer: http://www.zvg-portal.de/index.php?button=Termine%20suchen' \
    -H 'Connection: keep-alive' \
    --data 'ger_name=Chemnitz&order_by=2&land_abk=sn&ger_id=U1206&az1=&az2=&az3=&az4=&art=&obj=&obj_arr%5B%5D=3&obj_arr%5B%5D=15&obj_arr%5B%5D=16&obj_liste=15&obj_liste=16&str=&hnr=&plz=&ort=&ortsteil=&vtermin=&btermin=' \
    --compressed
*/

$headers = [
    'Origin' => 'http://www.zvg-portal.de',
    'Accept-Encoding' => 'gzip, deflate',
    'Accept-Language' => 'de-DE,de;q=0.8,en-US;q=0.6,en;q=0.4',
    'Upgrade-Insecure-Requests' => 1,
    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/56.0.2924.87 Safari/537.36',
    'Content-Type' => 'application/x-www-form-urlencoded',
    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
    'Cache-Control' => 'max-age=0',
    'Referer' => 'http://www.zvg-portal.de/index.php?button=Termine%20suchen',
    'Connection' => 'keep-alive',
];

$data_raw = 'ger_name=Chemnitz&order_by=2&land_abk=sn&ger_id=U1206&az1=&az2=&az3=&az4=&art=&obj=&obj_arr%5B%5D=3&obj_arr%5B%5D=15&obj_arr%5B%5D=16&obj_liste=15&obj_liste=16&str=&hnr=&plz=&ort=&ortsteil=&vtermin=&btermin=';
$data = [
    'ger_name' => 'Chemnitz',
    'order_by' => 2,
    'land_abk' => 'sn',
    'ger_id' => 'U1206',
    'az1' => '',
    'az2' => '',
    'az3' => '',
    'az4' => '',
    'art' => '',
    'obj' => '',
    'obj_arr[]' => [3, 15, 16],
    //'obj_liste' => 15,
    'obj_liste' => 16,
    'str' => '',
    'hnr' => '',
    'plz' => '',
    'ort' => '',
    'ortsteil' => '',
    'vtermin' => '',
    'btermin' => '',
];

// http_build_query

// http://docs.guzzlephp.org/en/stable/quickstart.html#making-a-request

$response = $client->post( '/index.php?button=Suchen&all=1', [
    //'form_params' => $data,
    'body' => $data_raw,
    'headers' => $headers,
    //'debug' => true,
]);

if ($response->getStatusCode() != 200) {
    die('Error: Status '.$response->getStatusCode());
}

$body = $response->getBody()->getContents();

if (!file_exists(__DIR__.'/storage')) {
    mkdir(__DIR__.'/storage', 0775);
}
file_put_contents(__DIR__.'/storage/last_response.html', $body);

echo "fetched\n";
//$dom = simplexml_load_string($body);
//echo $dom;

/**
 * Kern extrahieren
 */
if (strpos($body, '<!--Aktenzeichen--->') === false || strpos($body, '<!--Zwangsversteigerungen Ende-->') === false) {
    die('ERROR: cut');
}

$body = substr($body, strpos($body, '<!--Aktenzeichen--->'));
$body = substr($body, 0, strpos($body, '<!--Zwangsversteigerungen Ende-->'));
file_put_contents(__DIR__.'/storage/body_core.html', $body);

echo "extracted core\n";


/**
 * Split nach Aktenzeichen
 */
//$body = str_replace('<tr><td  colspan=3><hr></td></tr></table>', '', $body);

$items = explode('<!--Aktenzeichen--->', $body);
$items = array_filter($items, function($n) { return !empty($n); });
if (empty($items)) {
    die("ERROR: 0 items");
}
echo count($items)." items fetched\n";

/**
 * Items identifizieren und mit Cache abgleichen
 */
$cache_json = __DIR__.'/storage/items.json';
$cache = [];
if (file_exists($cache_json)) {
    $cache_loaded = json_decode(file_get_contents($cache_json), true);
    if (is_array($cache_loaded)) {
        $cache = $cache_loaded;
        echo count($cache)." items from cache\n";
    } else {
        echo "WARN cache invalid\n";
    }
}

$ids_cache = is_array($cache) ? array_keys($cache) : [];
$ids_fetched = [];
$notify_new = [];
$notify_changed = [];
foreach ($items as $i => $item) {
    $item = utf8_encode(trim($item));
    if (!preg_match('/<nobr>([A-Z0-9 \/]+)(&nbsp;\(Detailansicht\)|)<\/nobr>/', $item, $match)) {
//    if (!preg_match('/zvg_id=(\d+)/', $item, $match)) {
        echo "skip $i\n";
        print_r($item); print "\n";
        continue;
    }
    $id = $match[1];
    $ids_fetched[] = $id;
    if (!isset($cache[$id])) {
        $cache[$id] = $item;
        $notify_new[] = $item;
    } else {
        // geändert?
        if (md5($cache[$id]) != md5($item)) {
            $notify_changed[] = $item;
            $cache[$id] = $item;
        }
    }
}

/**
 * alte Einträge beräumen
 */
$ids_deleted = array_diff($ids_cache, $ids_fetched);
foreach($ids_deleted as $id) {
    unset($cache[$id]);
}

/**
 * Cache schreiben
 */
$json = json_encode($cache, JSON_PRETTY_PRINT);
file_put_contents($cache_json, $json);
chmod($cache_json, 0664);
echo "cache saved\n";

/**
 * Statistiken
 */
echo count($notify_new)." new, ".count($notify_changed)." changed, ".count($ids_deleted)." deleted\n";

/**
 * Benachrichtigen bei neuen od. geänderten Einträgen
 */
if (!empty($notify_new) || !empty($notify_changed)) {
    $mail_body = '<base href="http://www.zvg-portal.de/index.php">';
    if (!empty($notify_new)) {
        $mail_body.= "<h2>NEU</h2>\n"
            .implode("\n<hr/>\n", array_map(function($n) { return strip_tags($n, '<a>'); }, $notify_new));
    }
    if (!empty($notify_changed)) {
        $mail_body.= "<h2>GEÄNDERT</h2>\n"
            .implode("\n<hr/>\n", array_map(function($n) { return strip_tags($n, '<a>'); }, $notify_changed));
    }

    file_put_contents(__DIR__.'/storage/last_mail.html', $mail_body);
    echo "mail sent\n";
}
