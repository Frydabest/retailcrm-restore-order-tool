<?php

require_once __DIR__ . "/vendor/autoload.php";

define("URL", "YOUR_CRMURL_HERE");
define("KEY", "YOUR_APIKEY_HERE");
define("LOG_FILE", "restore.log");
define("CACHE_FILE", "cache.log");

$csvData = file_get_contents("orders.csv");
$csvData = explode("\n", $csvData);
$skipMode = false;

$client = new GuzzleHttp\Client([
    'verify' => false,
]);


if (file_exists(CACHE_FILE)) {
    $lastOrderId = trim(file_get_contents(CACHE_FILE));
    $skipMode = !empty($lastOrderId);
}

foreach ($csvData as $orderId) {
    $orderId = trim($orderId);

    // Skipping orders until the last processed one
    if ($skipMode) {
        if ($orderId === $lastProcessedOrder) {
            $skipMode = false;
        }
        continue;
    }

    $sinceId = 0;
    $items = [];
    $deliveryAddress = [];

    do {
        $response = $client->request(
            "GET",
            URL .
            "/api/v5/orders/history?" .
            http_build_query([
                "apiKey" => KEY,
                "filter[orderId]" => $orderId,
                "filter[sinceId]" => $sinceId,
            ]),
        );

        $response = json_decode($response->getBody(), true);

        foreach ($response["history"] as $historyElement) {
            // Saving full details of items or address ("Created" history entry)
            if (!empty($historyElement["created"])) {

                if (!empty($historyElement["order"]["items"])) {
                    $items = $historyElement["order"]["items"];
                }

                if (!empty($historyElement["order"]["delivery"]["address"])) {
                    $deliveryAddress = $historyElement["order"]["delivery"]["address"];
                }
            }

            // Processing product changes
            if (!empty($historyElement["order"]["items"])) {
                $items = $historyElement["order"]["items"];
            }

            if ($historyElement["field"] === "order_product") {
                if ($historyElement["oldValue"] === null) {
                    $items[] = $historyElement["item"];
                } else {
                    unset(
                        $items[
                            findItemById(
                                $historyElement["oldValue"]["id"],
                                $items,
                            )
                        ],
                    );
                }
            }


            if (strpos($historyElement["field"], "order_product.") !== false || strpos($historyElement["field"], "delivery_address.") !== false) {
                $explodedField = explode(".", $historyElement["field"]);
                $field = snakeToCamelCase($explodedField[1]);

                if ($explodedField[0] == "order_product") {
                    $itemIndex = findItemById($historyElement["item"]["id"], $items);
                    if ($itemIndex !== null) {
                        $items[$itemIndex][$field] = $historyElement["newValue"];
                    }
                }

                if ($explodedField[0] == "delivery_address") {
                    $deliveryAddress[$field] = $historyElement["newValue"];
                }
            }

            if (!empty($historyElement["deleted"])) {
                $historyElement["order"]["items"] = $items;

                // Restoring address changes
                if (!empty($deliveryAddress) && !empty($historyElement["order"]["delivery"]["address"])) {
                    $historyElement["order"]["delivery"]["address"] = $deliveryAddress;
                }

                unset($historyElement["order"]["customer"]);
                unset($historyElement["order"]["contact"]);
                unset($historyElement["id"]);
                unset($historyElement["source"]);

                // Calculating the total discount of products
                $totalDiscount = 0;
                foreach ($historyElement["order"]["items"] as $item) {
                    if (!empty($item["discountTotal"])) {
                        $totalDiscount += $item["discountTotal"];
                    }
                }

                // Adding field "discountManualAmount" in the order
                if ($totalDiscount > 0) {
                    $historyElement["order"]["discountManualAmount"] = $totalDiscount;
                }

                $originalOrderNumber = $historyElement["order"]["number"] ?? "неизвестно";

                // Taking order's data & "site" from order
                $orderData = $historyElement["order"];
                $site = $orderData["site"];
                unset($orderData["site"]);

                // Creating order via API
                try {
                    $apiResponse = $client->request(
                        'POST',
                        URL .
                        '/api/v5/orders/create?' .
                        http_build_query([
                            'apiKey' => KEY,
                        ]),
                        [
                            'form_params' => [
                                'order' => json_encode($orderData, JSON_UNESCAPED_UNICODE),
                                'site' => $site,
                            ],
                        ]
                    );

                    $apiResponseData = json_decode($apiResponse->getBody(), true);
                    if (isset($apiResponseData["success"]) && $apiResponseData["success"]) {
                        $newOrderId = $apiResponseData["id"] ?? "неизвестно";
                        $newOrderNumber = $apiResponseData["order"]["number"] ?? "неизвестно";

                        echo "Заказ успешно создан. ID: $newOrderId, Номер: $newOrderNumber\n";
                        file_put_contents(CACHE_FILE, $orderId);
                    } else {
                        $errorMsg = $apiResponseData["errorMsg"] ?? "Неизвестная ошибка";
                        echo "Ошибка при создании заказа: $errorMsg\n";
                        logMessage("Ошибка при восстановлении заказа $originalOrderNumber: $errorMsg");
                    }
                } catch (Exception $e) {
                    $errorMsg = $e->getMessage();
                    echo "Исключение при создании заказа: $errorMsg\n";
                    logMessage("Исключение при восстановлении заказа $originalOrderNumber: $errorMsg");
                    exit();
                }
            }
        }

        $sinceId = $response["history"][count($response["history"]) - 1]["id"];
    } while (
        $response["pagination"]["currentPage"] <
        $response["pagination"]["totalPageCount"]
    );
}

function snakeToCamelCase(string $snakeCaseString): string
{
    $camelCaseString = ucwords(str_replace("_", " ", $snakeCaseString));
    $camelCaseString = lcfirst(str_replace(" ", "", $camelCaseString));

    return $camelCaseString;
}

function findItemById(int $id, array $items): ?int
{
    foreach ($items as $key => $val) {
        if ($id === (int) $val["id"]) {
            return $key;
        }
    }

    return null;
}

function logMessage(string $message): void
{
    $timestamp = date("Y-m-d H:i:s");
    $logEntry = "[$timestamp] $message\n";
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
}
