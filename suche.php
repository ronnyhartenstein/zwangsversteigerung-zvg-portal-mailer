<?php

require __DIR__.'/vendor/autoload.php';

use GuzzleHttp\Client;

$config = require(__DIR__.'/config.php');

$base_uri = 'http://www.zvg-portal.de';
$details_dir = __DIR__.'/storage/details';

$client = new Client([
    // Base URI is used with relative requests
    'base_uri' => $base_uri,
    // You can set any number of default request options.
    'timeout'  => 5.0,
]);

function stop($msg)
{
    global $stats;
    $log = __DIR__ . '/storage/run.log';
    $text = '[' . date('Y-m-d H:i:s') . '] ' . $msg . (!empty($stats) ? ' ' . $stats : '')."\n";
    file_put_contents($log, $text, FILE_APPEND);
    exit($msg);
}

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
    'Content-Type' => 'application/x-www-form-urlencoded',
    'Referer' => $base_uri.'/index.php?button=Termine%20suchen',
];

$data_raw = 'ger_name=Chemnitz&order_by=2&land_abk=sn&ger_id=U1206&az1=&az2=&az3=&az4=&art=&obj=&obj_arr%5B%5D=3&obj_arr%5B%5D=15&obj_arr%5B%5D=16&obj_liste=15&obj_liste=16&str=&hnr=&plz=&ort=&ortsteil=&vtermin=&btermin=';
/*$data = [
    'ger_name' => 'Chemnitz',
    'order_by' => 2,
    'land_abk' => 'sn',
    'ger_id' => 'U1206',
    'obj_arr[]' => [3, 15, 16],
    'obj_liste' => 16,
];*/

// http_build_query

// http://docs.guzzlephp.org/en/stable/quickstart.html#making-a-request

$response = $client->post( '/index.php?button=Suchen&all=1', [
    //'form_params' => $data,
    'body' => $data_raw,
    'headers' => $headers,
    //'debug' => true,
]);

if ($response->getStatusCode() != 200) {
    stop('Error: Status '.$response->getStatusCode());
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
    stop('ERROR: cut');
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
    stop("ERROR: 0 items");
}
echo count($items)." items fetched\n";

/**
 * Items identifizieren und mit Cache abgleichen
 */
$cache_json = __DIR__.'/storage/items.json';
//unlink($cache_json); // Debug
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
$ids_deleted = [];
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
    if (preg_match('/Der Termin .+ wurde aufgehoben/', $item)) {
        $ids_deleted[] = $id;
    } else if (!isset($cache[$id])) {
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
$ids_deleted = array_merge($ids_deleted, array_diff($ids_cache, $ids_fetched));
foreach($ids_deleted as $id) {
    unset($cache[$id]);
    $details_file = $details_dir.'/'.$id.'.html';
    if (file_exists($details_file)) {
        unlink($details_file);
    }
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
$stats = count($notify_new)." neu, ".count($notify_changed)." geändert, ".count($ids_deleted)." gelöscht";
echo "$stats\n";

/**
 * Benachrichtigen bei neuen od. geänderten Einträgen
 */
if (empty($notify_new) && empty($notify_changed)) {
    stop("nothing to do..");
}

/**
 * Detailseiten herunterladen
 */

/*
curl 'http://www.zvg-portal.de/index.php?button=showZvg&zvg_id=31626&land_abk=sn' --compressed -XGET \
    -H 'Referer: http://www.zvg-portal.de/index.php?button=Suchen'
*/
function process_notify_items(&$items) {
    global $base_uri, $details_dir, $client, $config;
    if (!file_exists($details_dir)) {
        mkdir($details_dir, 0775);
    }
    $handler = function($m) use ($base_uri, $details_dir, $client, $config) {
        $url = $m[1];
        $id = $m[2];
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Referer' => 'http://www.zvg-portal.de/index.php?button=Suchen',
        ];
        $response = $client->get( '/index.php?button=showZvg&zvg_id='.$id.'&land_abk=sn', [
            'headers' => $headers,
            //'debug' => true,
        ]);
        if ($response->getStatusCode() != 200) {
            echo 'Error: fetch ID '.$id.' - '.$response->getStatusCode();
            return $url.'#notfound';
        }
        $body = $response->getBody()->getContents();
        $start = '<div id="inhalt"><!-- Inhalt -->';
        $end = '</div><!-- ende Inhalt -->';
        $body = substr($body, strpos($body, $start));
        $body = substr($body, 0, strpos($body, $end) + strlen($end));
        $body = '<html>
<header>
<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet">
</header>
<body>
        <div class="container">' . $body . '</div>
</body>
</html>';
        file_put_contents($details_dir.'/'.$id.'.html', $body);
        return 'href='.$config['web_host'].'/storage/details/'.$id.'.html';
    };
    foreach ($items as &$item) {
        if ($item = preg_replace_callback('/href=(index\.php\?button=showZvg&zvg_id=(\d+)&land_abk=sn)/', $handler, $item)) {
            echo ".";
        } else {
            echo "x";
        }
    }
}

/**
 * Mailbody bauen
 */

$mail_body = '';
if (!empty($notify_new)) {
    process_notify_items($notify_new);
    $mail_body.= "<h2>NEU</h2>\n"
        .'<table>'.implode("\n", $notify_new).'</table>';
}
if (!empty($notify_changed)) {
    process_notify_items($notify_changed);
    $mail_body.= "<h2>GEÄNDERT</h2>\n"
        .'<table>'.implode("\n", $notify_changed).'</table>';
}
file_put_contents(__DIR__.'/storage/last_mail.html', $mail_body);



/**
 * Mailen
 */

$subject = 'Zwangsversteigerung Update: '.$stats;
$from = 'Zwangsversteigerung Mailer';

$transport = (new Swift_SmtpTransport($config['mail_host'], $config['mail_port'], 'tls'))
    ->setUsername($config['mail_user'])
    ->setPassword($config['mail_pwd']);
$mailer = new Swift_Mailer($transport);
$message = (new Swift_Message($subject))
    ->setFrom([$config['mail_from'] => $from])
    ->setTo($config['mail_to'])
    ->setBody($mail_body, 'text/html');
$result = $mailer->send($message);
if (!$result) {
    stop("ERROR mail not sent");
}
echo "mail sent\n";
stop("done");

