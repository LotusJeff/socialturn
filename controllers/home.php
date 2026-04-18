<?php

function index() {
	header('Location: ' . u('queue', 'index'));
	exit;
}