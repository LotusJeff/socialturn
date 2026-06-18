<?php
declare(strict_types=1);

function index(): void {
	header('Location: ' . u('queue', 'index'));
	exit;
}