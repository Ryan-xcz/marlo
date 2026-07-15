<?php
// worker.php - Run this from your command prompt!
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;

// Connect to RabbitMQ
$connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
$channel = $connection->channel();

// Ensure the queue exists (matches the producer)
$channel->queue_declare('email_queue', false, true, false, false);

echo " [*] Background Worker Started.\n";
echo " [*] Waiting for registration tasks. To exit press CTRL+C\n\n";

// This is the function that fires every time a new message arrives
$callback = function ($msg) {
    // Decode the JSON sent by register_event.php
    $data = json_decode($msg->body, true);
    
    echo " [x] New Registration Received!\n";
    echo "     Processing ticket for: " . $data['student_name'] . "\n";
    
    // ========================================================
    // DO YOUR HEAVY LIFTING HERE
    // e.g., Generate a PDF ticket using TCPDF or FPDF
    // e.g., Send an email using PHPMailer
    // ========================================================
    
    // Simulating a task that takes 3 seconds to complete
    sleep(3); 
    
    echo " [v] Email successfully sent to: " . $data['email'] . "\n\n";
    
    // Tell RabbitMQ the job is 100% complete so it can delete the message
    $msg->ack();
};

// Tell RabbitMQ not to give more than one message to a worker at a time
$channel->basic_qos(null, 1, null);

// Start consuming
$channel->basic_consume('email_queue', '', false, false, false, false, $callback);

// Keep the script running forever
while ($channel->is_open()) {
    $channel->wait();
}

$channel->close();
$connection->close();
?>
