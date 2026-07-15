<?php
$autoload = __DIR__ . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    echo "ERROR: vendor/autoload.php not found." . PHP_EOL;
    echo "Run: composer require php-amqplib/php-amqplib" . PHP_EOL;
    exit();
}

require_once $autoload;

$connectionClass = 'PhpAmqpLib\\Connection\\AMQPStreamConnection';

if (!class_exists($connectionClass)) {
    echo "ERROR: PhpAmqpLib is not installed correctly." . PHP_EOL;
    echo "Run: composer require php-amqplib/php-amqplib" . PHP_EOL;
    exit();
}

try {
    $connection = new $connectionClass('localhost', 5672, 'guest', 'guest');
    $channel = $connection->channel();

    $queueName = 'email_queue';
    $channel->queue_declare($queueName, false, true, false, false);
    $channel->basic_qos(null, 1, null);

    echo "Waiting for messages from {$queueName}. Press CTRL+C to stop." . PHP_EOL;

    $callback = function ($msg) {
        $body = $msg->body;
        echo "Received: " . $body . PHP_EOL;

        $data = json_decode($body, true);
        if (is_array($data)) {
            echo "Action: " . ($data['action'] ?? 'N/A') . PHP_EOL;
            echo "Student: " . ($data['student_name'] ?? 'N/A') . PHP_EOL;
            echo "Email: " . ($data['email'] ?? 'N/A') . PHP_EOL;
            echo "Event: " . ($data['event'] ?? 'N/A') . PHP_EOL;
            echo "Time: " . ($data['timestamp'] ?? date('Y-m-d H:i:s')) . PHP_EOL;
        }

        $msg->ack();
        echo "Message processed." . PHP_EOL . PHP_EOL;
    };

    $channel->basic_consume($queueName, '', false, false, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();
} catch (Exception $e) {
    echo "RabbitMQ Consumer Error: " . $e->getMessage() . PHP_EOL;
}
?>
