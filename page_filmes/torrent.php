<?php

header("Access-Control-Allow-Origin: *");

$TORRENT_SERVER = "http://127.0.0.1:3000";
$API_SERVER = "http://127.0.0.1:8001";


/*
|--------------------------------------------------------------------------
| Resposta JSON
|--------------------------------------------------------------------------
*/

function jsonResponse($data, $status = 200)
{
    http_response_code($status);

    header("Content-Type: application/json; charset=utf-8");

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Pedido GET
|--------------------------------------------------------------------------
*/

function getRequest($url)
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            "Accept: application/json"
        ]
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);

        curl_close($ch);

        throw new Exception($error);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        "status" => $status,
        "body" => $response
    ];
}


/*
|--------------------------------------------------------------------------
| Pedido POST JSON
|--------------------------------------------------------------------------
*/

function postJson($url, $data)
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,

        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Accept: application/json"
        ],

        CURLOPT_POSTFIELDS => json_encode($data)
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);

        curl_close($ch);

        throw new Exception($error);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        "status" => $status,
        "body" => $response
    ];
}


/*
|--------------------------------------------------------------------------
| Ação
|--------------------------------------------------------------------------
*/

$action = $_GET["action"] ?? "";


/*
|--------------------------------------------------------------------------
| SEARCH
|
| torrent.php?action=search&q=Titanic
|--------------------------------------------------------------------------
*/

if ($action === "search") {

    $query = trim($_GET["q"] ?? "");

    if ($query === "") {
        jsonResponse([
            "status" => "error",
            "message" => "Falta o parâmetro q."
        ], 400);
    }

    try {

        $url = $API_SERVER .
            "/search?q=" .
            urlencode($query);

        $response = getRequest($url);

        http_response_code($response["status"]);

        header("Content-Type: application/json; charset=utf-8");

        echo $response["body"];

    } catch (Exception $e) {

        jsonResponse([
            "status" => "error",
            "message" => $e->getMessage()
        ], 500);
    }

    exit;
}


/*
|--------------------------------------------------------------------------
| START
|
| torrent.php?action=start&magnet=...
|--------------------------------------------------------------------------
*/

if ($action === "start") {

    $magnet = $_GET["magnet"] ?? "";

    if ($magnet === "") {
        jsonResponse([
            "status" => "error",
            "message" => "Falta o magnet."
        ], 400);
    }

    try {

        $response = postJson(
            $TORRENT_SERVER . "/start",
            [
                "magnet" => $magnet
            ]
        );

        http_response_code($response["status"]);

        header("Content-Type: application/json; charset=utf-8");

        echo $response["body"];

    } catch (Exception $e) {

        jsonResponse([
            "status" => "error",
            "message" => $e->getMessage()
        ], 500);
    }

    exit;
}


/*
|--------------------------------------------------------------------------
| STATUS
|
| torrent.php?action=status&id=XXXXXXXX
|--------------------------------------------------------------------------
*/

if ($action === "status") {

    $id = trim($_GET["id"] ?? "");

    if ($id === "") {
        jsonResponse([
            "status" => "error",
            "message" => "Falta o ID do torrent."
        ], 400);
    }

    try {

        $url = $TORRENT_SERVER .
            "/status/" .
            rawurlencode($id);

        $response = getRequest($url);

        http_response_code($response["status"]);

        header("Content-Type: application/json; charset=utf-8");

        echo $response["body"];

    } catch (Exception $e) {

        jsonResponse([
            "status" => "error",
            "message" => $e->getMessage()
        ], 500);
    }

    exit;
}


/*
|--------------------------------------------------------------------------
| STREAM
|
| torrent.php?action=stream&id=XXXXXXXX
|
| Aqui NÃO devolvemos JSON.
| Fazemos proxy do vídeo entre o Node e o browser.
|--------------------------------------------------------------------------
*/

if ($action === "stream") {

    $id = trim($_GET["id"] ?? "");

    if ($id === "") {
        http_response_code(400);
        exit("Falta o ID do torrent.");
    }

    $url = $TORRENT_SERVER .
        "/stream/" .
        rawurlencode($id);


    /*
    |--------------------------------------------------------------------------
    | Headers enviados pelo browser
    |--------------------------------------------------------------------------
    */

    $headers = [];

    if (isset($_SERVER["HTTP_RANGE"])) {
        $headers[] = "Range: " . $_SERVER["HTTP_RANGE"];
    }


    /*
    |--------------------------------------------------------------------------
    | cURL
    |--------------------------------------------------------------------------
    */

    $ch = curl_init($url);

    curl_setopt_array($ch, [

        CURLOPT_RETURNTRANSFER => false,

        CURLOPT_FOLLOWLOCATION => true,

        CURLOPT_CONNECTTIMEOUT => 10,

        CURLOPT_TIMEOUT => 0,

        CURLOPT_HTTPHEADER => $headers,

        CURLOPT_HEADERFUNCTION => function ($curl, $header) {

            // O cURL exige o tamanho original (com \r\n), não o do trim
            $len = strlen($header);

            $header = trim($header);

            if ($header === "") {
                return $len;
            }

            /*
            * Ignorar a linha HTTP/1.1 200/206...
            */

            if (
                stripos($header, "HTTP/") === 0
            ) {
                return $len;
            }


            /*
            * Headers importantes do vídeo
            */

            $allowed = [
                "content-type",
                "content-length",
                "content-range",
                "accept-ranges",
                "cache-control",
                "etag",
                "last-modified"
            ];

            $parts = explode(":", $header, 2);

            if (count($parts) !== 2) {
                return $len;
            }

            $name = strtolower(trim($parts[0]));

            if (in_array($name, $allowed, true)) {
                header($header);
            }

            return $len;
        },

        CURLOPT_WRITEFUNCTION => function ($curl, $data) {

            echo $data;

            if (ob_get_level()) {
                ob_flush();
            }

            flush();

            return strlen($data);
        }
    ]);


    curl_exec($ch);

    curl_close($ch);

    exit;
}


/*
|--------------------------------------------------------------------------
| Ação inválida
|--------------------------------------------------------------------------
*/

jsonResponse([
    "status" => "error",
    "message" => "Ação inválida.",
    "available_actions" => [
        "search",
        "start",
        "status",
        "stream"
    ]
], 400);