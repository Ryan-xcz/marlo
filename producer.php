<?php
$autoload = __DIR__ . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    echo "ERROR: vendor/autoload.php not found." . PHP_EOL;
    echo "Run: composer require php-amqplib/php-amqplib" . PHP_EOL;
    exit();
}

require_once $autoload;

$connectionClass = 'PhpAmqpLib\\Connection\\AMQPStreamConnection';
$messageClass = 'PhpAmqpLib\\Message\\AMQPMessage';

if (!class_exists($connectionClass) || !class_exists($messageClass)) {
    echo "ERROR: PhpAmqpLib is not installed correctly." . PHP_EOL;
    echo "Run: composer require php-amqplib/php-amqplib" . PHP_EOL;
    exit();
}

try {
    $connection = new $connectionClass('localhost', 5672, 'guest', 'guest');
    $channel = $connection->channel();

    $queueName = 'email_queue';
    $channel->queue_declare($queueName, false, true, false, false);

    $payload = json_encode([
        'action' => 'send_registration_email',
        'student_name' => 'Test Student',
        'email' => 'test@example.com',
        'event' => 'Test Event Registration',
        'timestamp' => date('Y-m-d H:i:s')
    ]);

    $msg = new $messageClass($payload, ['delivery_mode' => 2]);
    $channel->basic_publish($msg, '', $queueName);

    echo "Message sent successfully: " . $payload . PHP_EOL;

    $channel->close();
    $connection->close();
} catch (Exception $e) {
    echo "RabbitMQ Producer Error: " . $e->getMessage() . PHP_EOL;
}
?>
