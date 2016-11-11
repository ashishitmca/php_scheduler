<?php
	$conn = new mysqli('localhost', 'root', 'smith', 'scheduler');
	if ($conn->connect_error) {
		die("Connection failed: " . $conn->connect_error);
	}
