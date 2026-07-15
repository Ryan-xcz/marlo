<?php

class Event {

    public $title;
    public $venue;

    public function __construct($title, $venue){
        $this->title = $title;
        $this->venue = $venue;
    }

    public function displayEvent(){
        return $this->title . " - " . $this->venue;
    }
}

?>
