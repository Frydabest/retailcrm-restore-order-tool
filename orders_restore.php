<?php

require_once __DIR__ . "/vendor/autoload.php";

define("URL", "YOUR_CRMURL_HERE");
define("KEY", "YOUR_APIKEY_HERE");
define("LOG_FILE", "restore.log");
define("PROGRESS_FILE", "progress.log");

$csvData = file_get_contents("orders.csv");
$csvData = explode("\n", $csvData);

logMessage("Начало восстановления заказов");

$client = new GuzzleHttp\Client([
    'verify' => false,
]);

$lastProcessedOrder = getLastProcessedOrder();
$skipMode = !empty($lastProcessedOrder);

if ($skipMode) {
    logMessage("Найден прогресс выполнения. Пропускаем до заказа: $lastProcessedOrder");
}

foreach ($csvData as $orderId) {
    $orderId = trim($orderId);
    if (empty($orderId)) {
        continue;
    }

    // Skipping orders until the last processed one
    if ($skipMode) {
        if ($orderId === $lastProcessedOrder) {
            logMessage("Достигнут последний обработанный заказ: $orderId. Продолжаем с следующего.");
            $skipMode = false;
        }
        continue;
    }

    logMessage("Начало обработки заказа: $orderId");
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
            // Saving full details of items ("Created" history entry)
            if (!empty($historyElement["created"]) && !empty($historyElement["order"]["items"])) {
                $items = $historyElement["order"]["items"];
            }

            // Saving the original address ("Created" history entry)
            if (!empty($historyElement["created"]) && !empty($historyElement["order"]["delivery"]["address"])) {
                foreach ($historyElement["order"]["delivery"]["address"] as $key => $value) {
                    $deliveryAddress[$key] = $value;
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

            // Processing product's field changes
            if (strpos($historyElement["field"], "order_product.") !== false) {
                $explodedField = explode(".", $historyElement["field"]);
                $field = snakeToCamelCase($explodedField[1]);

                $itemIndex = findItemById($historyElement["item"]["id"], $items);
                if ($itemIndex !== null) {
                    $items[$itemIndex][$field] = $historyElement["newValue"];
                }
            }

            // Processing address changes
            if (strpos($historyElement["field"], "delivery_address.") !== false) {
                $explodedField = explode(".", $historyElement["field"]);
                $addressField = snakeToCamelCase($explodedField[1]);

                $deliveryAddress[$addressField] = $historyElement["newValue"];
            }

            if (!empty($historyElement["deleted"])) {
                $historyElement["order"]["items"] = $items;

                // Restoring address changes
                if (!empty($deliveryAddress)) {
                    if (!empty($historyElement["order"]["delivery"]["address"])) {
                        foreach ($deliveryAddress as $key => $value) {
                            $historyElement["order"]["delivery"]["address"][$key] = $value;
                        }
                    } else {
                        $historyElement["order"]["delivery"]["address"] = $deliveryAddress;
                    }
                }

                unset($historyElement["order"]["customer"]);
                unset($historyElement["order"]["contact"]);
                unset($historyElement["id"]);
                unset($historyElement["source"]);

                unset($historyElement["field"]);
                unset($historyElement["deleted"]);
                unset($historyElement["oldValue"]);
                unset($historyElement["newValue"]);

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
                logMessage("Найден удаленный заказ: $originalOrderNumber (ID: $orderId)");

                // Taking order's data & "site" from order
                $orderData = $historyElement["order"];
                $site = $orderData["site"];
                unset($orderData["site"]);

                // Creating order via API
                try {
                    $createResponse = $client->request(
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

                    $createResponseData = json_decode($createResponse->getBody(), true);
                    if (isset($createResponseData["success"]) && $createResponseData["success"]) {
                        $newOrderId = $createResponseData["id"] ?? "неизвестно";
                        $newOrderNumber = $createResponseData["order"]["number"] ?? "неизвестно";

                        echo "Заказ успешно создан. ID: $newOrderId, Номер: $newOrderNumber\n";
                        logMessage("Заказ восстановлен: $originalOrderNumber -> ID: $newOrderId, Номер: $newOrderNumber");
                        saveProgress($orderId);
                    } else {
                        $errorMsg = $createResponseData["errorMsg"] ?? "Неизвестная ошибка";
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

    logMessage("Завершена обработка заказа: $orderId");
}

logMessage("Восстановление заказов завершено");

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

function saveProgress(string $orderId): void
{
    file_put_contents(PROGRESS_FILE, $orderId);
}

function getLastProcessedOrder(): ?string
{
    if (file_exists(PROGRESS_FILE)) {
        $lastOrderId = trim(file_get_contents(PROGRESS_FILE));
        return !empty($lastOrderId) ? $lastOrderId : null;
    }
    return null;
}