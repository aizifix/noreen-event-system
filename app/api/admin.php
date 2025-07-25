<?php
require 'db_connect.php';

// Add CORS headers for API access
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Set content type to JSON
header("Content-Type: application/json");

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

class Admin {
    private $conn;
    private $pdo;

    public function __construct($pdo) {
        $this->conn = $pdo;
        $this->pdo = $pdo;  // For compatibility with new methods
    }

    public function createEvent($data) {
        try {
            // Log the incoming data for debugging
            error_log("createEvent received data: " . json_encode($data));

            // Filter input data to only include expected fields to prevent SQL injection of unknown columns
            $allowedFields = [
                'operation', 'original_booking_reference', 'user_id', 'admin_id', 'organizer_id',
                'event_title', 'event_theme', 'event_description', 'event_type_id', 'guest_count',
                'event_date', 'start_time', 'end_time', 'package_id', 'venue_id', 'total_budget',
                'down_payment', 'payment_method', 'payment_schedule_type_id', 'reference_number',
                'additional_notes', 'event_status', 'is_recurring', 'recurrence_rule',
                'client_signature', 'finalized_at', 'event_attachments', 'payment_attachments',
                'components', 'timeline'
            ];

            $filteredData = [];
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $filteredData[$field] = $data[$field];
                }
            }

            // Use filtered data from here on
            $data = $filteredData;
            error_log("createEvent filtered data: " . json_encode($data));

            $this->conn->beginTransaction();

            // Validate required event fields
            $required = ['user_id', 'admin_id', 'event_title', 'event_type_id', 'guest_count', 'event_date'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    error_log("createEvent error: Missing required field: $field");
                    return json_encode(["status" => "error", "message" => "$field is required"]);
                }
            }

            // Validate foreign key references before insertion
            // Check if user exists
            $userCheck = $this->conn->prepare("SELECT user_id FROM tbl_users WHERE user_id = ? LIMIT 1");
            $userCheck->execute([$data['user_id']]);
            if (!$userCheck->fetch(PDO::FETCH_ASSOC)) {
                return json_encode(["status" => "error", "message" => "Invalid user_id: User does not exist"]);
            }

            // Check if admin exists
            $adminCheck = $this->conn->prepare("SELECT user_id FROM tbl_users WHERE user_id = ? AND user_role = 'admin' LIMIT 1");
            $adminCheck->execute([$data['admin_id']]);
            if (!$adminCheck->fetch(PDO::FETCH_ASSOC)) {
                return json_encode(["status" => "error", "message" => "Invalid admin_id: Admin user does not exist"]);
            }

            // Check if event type exists
            $eventTypeCheck = $this->conn->prepare("SELECT event_type_id FROM tbl_event_type WHERE event_type_id = ? LIMIT 1");
            $eventTypeCheck->execute([$data['event_type_id']]);
            if (!$eventTypeCheck->fetch(PDO::FETCH_ASSOC)) {
                return json_encode(["status" => "error", "message" => "Invalid event_type_id: Event type does not exist. Available types: 1=Wedding, 2=Anniversary, 3=Birthday, 4=Corporate, 5=Others"]);
            }

            // Check if package exists (if provided)
            if (!empty($data['package_id'])) {
                $packageCheck = $this->conn->prepare("SELECT package_id FROM tbl_packages WHERE package_id = ? LIMIT 1");
                $packageCheck->execute([$data['package_id']]);
                if (!$packageCheck->fetch(PDO::FETCH_ASSOC)) {
                    return json_encode(["status" => "error", "message" => "Invalid package_id: Package does not exist"]);
                }
            }

            // Check if venue exists (if provided)
            if (!empty($data['venue_id'])) {
                $venueCheck = $this->conn->prepare("SELECT venue_id FROM tbl_venue WHERE venue_id = ? LIMIT 1");
                $venueCheck->execute([$data['venue_id']]);
                if (!$venueCheck->fetch(PDO::FETCH_ASSOC)) {
                    return json_encode(["status" => "error", "message" => "Invalid venue_id: Venue does not exist"]);
                }
            }

            // Wedding-specific business rule validation
            if ($data['event_type_id'] == 1) { // Wedding event
                // Check if there's already a wedding on the same date
                $weddingCheck = $this->conn->prepare("
                    SELECT event_id, event_title
                    FROM tbl_events
                    WHERE event_date = ?
                    AND event_type_id = 1
                    AND event_status NOT IN ('cancelled', 'completed')
                    LIMIT 1
                ");
                $weddingCheck->execute([$data['event_date']]);
                $existingWedding = $weddingCheck->fetch(PDO::FETCH_ASSOC);

                if ($existingWedding) {
                    return json_encode([
                        "status" => "error",
                        "message" => "Business rule violation: Only one wedding is allowed per day. There is already a wedding scheduled on " . $data['event_date'] . "."
                    ]);
                }

                // Check if there are other events on the same date (weddings cannot be scheduled alongside other events)
                $otherEventsCheck = $this->conn->prepare("
                    SELECT event_id, event_title, event_type_id
                    FROM tbl_events
                    WHERE event_date = ?
                    AND event_type_id != 1
                    AND event_status NOT IN ('cancelled', 'completed')
                ");
                $otherEventsCheck->execute([$data['event_date']]);
                $otherEvents = $otherEventsCheck->fetchAll();

                if (!empty($otherEvents)) {
                    $eventTypes = array_unique(array_column($otherEvents, 'event_type_id'));
                    return json_encode([
                        "status" => "error",
                        "message" => "Business rule violation: Weddings cannot be scheduled alongside other events. There are other events already scheduled on " . $data['event_date'] . "."
                    ]);
                }
            } else {
                // For non-wedding events, check if there's a wedding on the same date
                $weddingCheck = $this->conn->prepare("
                    SELECT event_id, event_title
                    FROM tbl_events
                    WHERE event_date = ?
                    AND event_type_id = 1
                    AND event_status NOT IN ('cancelled', 'completed')
                    LIMIT 1
                ");
                $weddingCheck->execute([$data['event_date']]);
                $existingWedding = $weddingCheck->fetch(PDO::FETCH_ASSOC);

                if ($existingWedding) {
                    return json_encode([
                        "status" => "error",
                        "message" => "Business rule violation: Other events cannot be scheduled on the same date as a wedding. There is already a wedding scheduled on " . $data['event_date'] . "."
                    ]);
                }
            }

            // Insert the main event
            $sql = "INSERT INTO tbl_events (
                        original_booking_reference, user_id, admin_id, organizer_id, event_title,
                        event_theme, event_description, event_type_id, guest_count, event_date, start_time, end_time,
                        package_id, venue_id, total_budget, down_payment, payment_method,
                        reference_number, additional_notes, event_status, payment_schedule_type_id,
                        is_recurring, recurrence_rule, client_signature, finalized_at, event_attachments
                    ) VALUES (
                        :original_booking_reference, :user_id, :admin_id, :organizer_id, :event_title,
                        :event_theme, :event_description, :event_type_id, :guest_count, :event_date, :start_time, :end_time,
                        :package_id, :venue_id, :total_budget, :down_payment, :payment_method,
                        :reference_number, :additional_notes, :event_status, :payment_schedule_type_id,
                        :is_recurring, :recurrence_rule, :client_signature, :finalized_at, :event_attachments
                    )";

            $stmt = $this->conn->prepare($sql);

            $eventParams = [
                ':original_booking_reference' => $data['original_booking_reference'] ?? null,
                ':user_id' => $data['user_id'],
                ':admin_id' => $data['admin_id'],
                ':organizer_id' => $data['organizer_id'] ?? null,
                ':event_title' => $data['event_title'],
                ':event_theme' => $data['event_theme'] ?? null,
                ':event_description' => $data['event_description'] ?? null,
                ':event_type_id' => $data['event_type_id'],
                ':guest_count' => $data['guest_count'],
                ':event_date' => $data['event_date'],
                ':start_time' => $data['start_time'] ?? '10:00:00',
                ':end_time' => $data['end_time'] ?? '18:00:00',
                ':package_id' => $data['package_id'] ?? null,
                ':venue_id' => $data['venue_id'] ?? null,
                ':total_budget' => $data['total_budget'] ?? 0,
                ':down_payment' => $data['down_payment'] ?? 0,
                ':payment_method' => $data['payment_method'] ?? null,
                ':reference_number' => $data['reference_number'] ?? null,
                ':additional_notes' => $data['additional_notes'] ?? null,
                ':event_status' => $data['event_status'] ?? 'draft',
                ':payment_schedule_type_id' => $data['payment_schedule_type_id'] ?? 2,
                ':is_recurring' => $data['is_recurring'] ?? false,
                ':recurrence_rule' => $data['recurrence_rule'] ?? null,
                ':client_signature' => $data['client_signature'] ?? null,
                ':finalized_at' => $data['finalized_at'] ?? null,
                ':event_attachments' => $data['event_attachments'] ?? null
            ];

            error_log("createEvent SQL params: " . json_encode($eventParams));
            $stmt->execute($eventParams);

            $eventId = $this->conn->lastInsertId();
            error_log("createEvent: Event created with ID: $eventId");

            // Insert event components if provided
            if (!empty($data['components']) && is_array($data['components'])) {
                foreach ($data['components'] as $index => $component) {
                    // Validate component
                    if (empty($component['component_name'])) {
                        continue; // Skip invalid components
                    }

                    $sql = "INSERT INTO tbl_event_components (
                                event_id, component_name, component_description,
                                component_price, is_custom, is_included,
                                original_package_component_id, supplier_id, offer_id, display_order
                            ) VALUES (
                                :event_id, :name, :description,
                                :price, :is_custom, :is_included,
                                :original_package_component_id, :supplier_id, :offer_id, :display_order
                            )";

                    $stmt = $this->conn->prepare($sql);
                    $stmt->execute([
                        ':event_id' => $eventId,
                        ':name' => $component['component_name'],
                        ':description' => $component['component_description'] ?? null,
                        ':price' => $component['component_price'] ?? 0,
                        ':is_custom' => $component['is_custom'] ?? false,
                        ':is_included' => $component['is_included'] ?? true,
                        ':original_package_component_id' => $component['original_package_component_id'] ?? null,
                        ':supplier_id' => $component['supplier_id'] ?? null,
                        ':offer_id' => $component['offer_id'] ?? null,
                        ':display_order' => $index
                    ]);
                }
            }

            // Insert timeline items if provided
            if (!empty($data['timeline']) && is_array($data['timeline'])) {
                foreach ($data['timeline'] as $index => $item) {
                    // Validate timeline item
                    if (empty($item['activity_title']) || empty($item['activity_date']) || empty($item['start_time'])) {
                        continue; // Skip invalid timeline items
                    }

                    $sql = "INSERT INTO tbl_event_timeline (
                                event_id, component_id, activity_title,
                                activity_date, start_time, end_time,
                                location, notes, assigned_to,
                                status, display_order
                            ) VALUES (
                                :event_id, :component_id, :title,
                                :date, :start_time, :end_time,
                                :location, :notes, :assigned_to,
                                :status, :display_order
                            )";

                    $stmt = $this->conn->prepare($sql);
                    $stmt->execute([
                        ':event_id' => $eventId,
                        ':component_id' => $item['component_id'] ?? null,
                        ':title' => $item['activity_title'],
                        ':date' => $item['activity_date'],
                        ':start_time' => $item['start_time'],
                        ':end_time' => $item['end_time'] ?? null,
                        ':location' => $item['location'] ?? null,
                        ':notes' => $item['notes'] ?? null,
                        ':assigned_to' => $item['assigned_to'] ?? null,
                        ':status' => $item['status'] ?? 'pending',
                        ':display_order' => $index
                    ]);
                }
            }

            // Create initial payment record if down payment is specified
            error_log("createEvent: Checking payment data - down_payment: " . ($data['down_payment'] ?? 'null') . ", payment_method: " . ($data['payment_method'] ?? 'null'));

            if (!empty($data['down_payment']) && $data['down_payment'] > 0) {
                error_log("createEvent: Creating payment record with amount: " . $data['down_payment']);

                // Check for duplicate payment reference if provided
                if (!empty($data['reference_number'])) {
                    $referenceCheckSql = "SELECT payment_id FROM tbl_payments WHERE payment_reference = ? LIMIT 1";
                    $referenceCheckStmt = $this->conn->prepare($referenceCheckSql);
                    $referenceCheckStmt->execute([$data['reference_number']]);
                    if ($referenceCheckStmt->fetch(PDO::FETCH_ASSOC)) {
                        $this->conn->rollback();
                        error_log("createEvent: Duplicate payment reference detected: " . $data['reference_number']);
                        return json_encode(["status" => "error", "message" => "Payment reference already exists. Please use a unique reference number."]);
                    }
                }

                $paymentSql = "INSERT INTO tbl_payments (
                    event_id, client_id, payment_method, payment_amount,
                    payment_notes, payment_status, payment_date, payment_reference
                ) VALUES (
                    :event_id, :client_id, :payment_method, :payment_amount,
                    :payment_notes, :payment_status, :payment_date, :payment_reference
                )";

                $paymentStmt = $this->conn->prepare($paymentSql);
                $paymentParams = [
                    ':event_id' => $eventId,
                    ':client_id' => $data['user_id'],
                    ':payment_method' => $data['payment_method'] ?? 'cash',
                    ':payment_amount' => floatval($data['down_payment']), // Ensure it's a number
                    ':payment_notes' => 'Initial down payment for event creation',
                    ':payment_status' => 'completed', // Always mark down payments as completed since they're being processed during event creation
                    ':payment_date' => date('Y-m-d'),
                    ':payment_reference' => $data['reference_number'] ?? null
                ];

                error_log("createEvent: Payment params: " . json_encode($paymentParams));
                $paymentStmt->execute($paymentParams);

                $paymentId = $this->conn->lastInsertId();
                error_log("createEvent: Payment created with ID: " . $paymentId);

                // Handle payment attachments if any were uploaded
                if (!empty($data['payment_attachments']) && is_array($data['payment_attachments'])) {
                    error_log("createEvent: Processing " . count($data['payment_attachments']) . " payment attachments");
                    $attachments = [];
                    foreach ($data['payment_attachments'] as $attachment) {
                        if (isset($attachment['file_path']) && isset($attachment['original_name'])) {
                            $attachments[] = [
                                'file_name' => basename($attachment['file_path']),
                                'original_name' => $attachment['original_name'],
                                'file_path' => $attachment['file_path'],
                                'file_size' => $attachment['file_size'] ?? 0,
                                'file_type' => $attachment['file_type'] ?? 'application/octet-stream',
                                'description' => $attachment['description'] ?? 'Payment proof for down payment',
                                'proof_type' => $attachment['proof_type'] ?? 'receipt',
                                'uploaded_at' => date('Y-m-d H:i:s'),
                            ];
                        }
                    }

                    if (!empty($attachments)) {
                        $updateAttachmentsSql = "UPDATE tbl_payments SET payment_attachments = ? WHERE payment_id = ?";
                        $updateAttachmentsStmt = $this->conn->prepare($updateAttachmentsSql);
                        $updateAttachmentsStmt->execute([json_encode($attachments), $paymentId]);
                        error_log("createEvent: Payment attachments saved: " . count($attachments) . " files");
                    }
                } else {
                    error_log("createEvent: No payment attachments provided");
                }
            } else {
                error_log("createEvent: No payment record created - down_payment is empty or zero");
            }

            // If this event was created from a booking, mark the booking as converted
            if (!empty($data['original_booking_reference'])) {
                $bookingConvertSql = "UPDATE tbl_bookings
                                     SET booking_status = 'converted', updated_at = NOW()
                                     WHERE booking_reference = :booking_reference";
                $bookingStmt = $this->conn->prepare($bookingConvertSql);
                $bookingStmt->execute([':booking_reference' => $data['original_booking_reference']]);
            }

            $this->conn->commit();
            error_log("createEvent: Transaction committed successfully");

            return json_encode([
                "status" => "success",
                "message" => "Event created successfully",
                "event_id" => $eventId
            ]);

        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("createEvent error: " . $e->getMessage());
            error_log("createEvent stack trace: " . $e->getTraceAsString());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function createCustomizedPackage($data) {
        try {
            $this->conn->beginTransaction();

            // Validate required fields
            $required = ['admin_id', 'package_title', 'event_type_id', 'guest_capacity', 'components'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return json_encode(["status" => "error", "message" => "$field is required"]);
                }
            }

            // Calculate total package price from components
            $totalPrice = 0;
            foreach ($data['components'] as $component) {
                $totalPrice += floatval($component['component_price'] || 0);
            }

            // Create the customized package
            $sql = "INSERT INTO tbl_packages (
                        package_title, package_description, package_price, guest_capacity,
                        created_by, is_active, original_price, is_price_locked, price_lock_date,
                        customized_package
                    ) VALUES (
                        :package_title, :package_description, :package_price, :guest_capacity,
                        :created_by, 1, :original_price, 0, NULL, 1
                    )";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':package_title' => $data['package_title'],
                ':package_description' => $data['package_description'] ?? 'Customized package created from event builder',
                ':package_price' => $totalPrice,
                ':guest_capacity' => $data['guest_capacity'],
                ':created_by' => $data['admin_id'],
                ':original_price' => $totalPrice
            ]);

            $packageId = $this->conn->lastInsertId();

            // Link package to event types
            $eventTypeSql = "INSERT INTO tbl_package_event_types (package_id, event_type_id) VALUES (?, ?)";
            $eventTypeStmt = $this->conn->prepare($eventTypeSql);
            $eventTypeStmt->execute([$packageId, $data['event_type_id']]);

            // Add components to the package
            foreach ($data['components'] as $index => $component) {
                $componentSql = "INSERT INTO tbl_package_components (
                                    package_id, component_name, component_description,
                                    component_price, display_order
                                ) VALUES (?, ?, ?, ?, ?)";

                $componentStmt = $this->conn->prepare($componentSql);
                $componentStmt->execute([
                    $packageId,
                    $component['component_name'],
                    $component['component_description'] ?? '',
                    $component['component_price'],
                    $index
                ]);
            }

            // Link venue if provided
            if (!empty($data['venue_id'])) {
                $venueSql = "INSERT INTO tbl_package_venues (package_id, venue_id) VALUES (?, ?)";
                $venueStmt = $this->conn->prepare($venueSql);
                $venueStmt->execute([$packageId, $data['venue_id']]);
            }

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Customized package created successfully",
                "package_id" => $packageId,
                "package_price" => $totalPrice
            ]);

        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("createCustomizedPackage error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Failed to create customized package: " . $e->getMessage()]);
        }
    }

    // Add other essential methods that might be needed
    public function getClients() {
        try {
            $sql = "SELECT
                        u.user_id,
                        u.user_firstName,
                        u.user_lastName,
                        u.user_email,
                        u.user_contact,
                        u.user_pfp,
                        u.created_at as registration_date,
                        COUNT(DISTINCT e.event_id) as total_events,
                        COUNT(DISTINCT b.booking_id) as total_bookings,
                        COALESCE(SUM(p.payment_amount), 0) as total_payments,
                        MAX(e.event_date) as last_event_date
                    FROM tbl_users u
                    LEFT JOIN tbl_events e ON u.user_id = e.user_id
                    LEFT JOIN tbl_bookings b ON u.user_id = b.user_id
                    LEFT JOIN tbl_payments p ON e.event_id = p.event_id AND p.payment_status = 'completed'
                    WHERE u.user_role = 'client'
                    GROUP BY u.user_id
                    ORDER BY u.user_firstName, u.user_lastName";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();

            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "clients" => $clients
            ]);
        } catch (Exception $e) {
            error_log("getClients error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getAvailableBookings() {
        try {
            $sql = "SELECT
                        b.booking_id,
                        b.booking_reference,
                        b.user_id,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                        u.user_email as client_email,
                        u.user_contact as client_phone,
                        b.event_type_id,
                        et.event_name as event_type_name,
                        b.event_name,
                        b.event_date,
                        b.event_time,
                        b.guest_count,
                        b.venue_id,
                        v.venue_title as venue_name,
                        b.package_id,
                        p.package_title as package_name,
                        b.notes,
                        b.booking_status,
                        b.created_at
                    FROM tbl_bookings b
                    JOIN tbl_users u ON b.user_id = u.user_id
                    JOIN tbl_event_type et ON b.event_type_id = et.event_type_id
                    LEFT JOIN tbl_venue v ON b.venue_id = v.venue_id
                    LEFT JOIN tbl_packages p ON b.package_id = p.package_id
                    LEFT JOIN tbl_events e ON b.booking_reference = e.original_booking_reference
                    WHERE b.booking_status = 'confirmed'
                    AND e.event_id IS NULL
                    ORDER BY b.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "bookings" => $bookings
            ]);
        } catch (Exception $e) {
            error_log("getAvailableBookings error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getBookingByReference($reference) {
        try {
            $sql = "SELECT b.*,
                        u.user_id, u.user_firstName, u.user_lastName, u.user_email, u.user_contact,
                        et.event_name as event_type_name,
                        v.venue_title as venue_name,
                        p.package_title as package_name,
                        CASE WHEN e.event_id IS NOT NULL THEN 1 ELSE 0 END as is_converted,
                        e.event_id as converted_event_id
                    FROM tbl_bookings b
                    JOIN tbl_users u ON b.user_id = u.user_id
                    JOIN tbl_event_type et ON b.event_type_id = et.event_type_id
                    LEFT JOIN tbl_venue v ON b.venue_id = v.venue_id
                    LEFT JOIN tbl_packages p ON b.package_id = p.package_id
                    LEFT JOIN tbl_events e ON b.booking_reference = e.original_booking_reference
                    WHERE b.booking_reference = :reference";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':reference' => $reference]);

            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($booking) {
                return json_encode(["status" => "success", "booking" => $booking]);
            } else {
                return json_encode(["status" => "error", "message" => "Booking not found"]);
            }
        } catch (Exception $e) {
            error_log("getBookingByReference error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    // Placeholder methods for missing functionality - these prevent fatal errors
    // ==================== SUPPLIER MANAGEMENT ====================

    // Create a new supplier (Admin only)
    // Enhanced supplier creation with auto-generated credentials and email notification
    public function createSupplier($data) {
        try {
            $this->conn->beginTransaction();

            // Validate required fields
            $required = ['business_name', 'contact_number', 'contact_email', 'supplier_type'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("$field is required");
                }
            }

            // Validate email format
            if (!filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format");
            }

            // Check for duplicate business name or email
            $checkStmt = $this->conn->prepare("SELECT supplier_id FROM tbl_suppliers WHERE (business_name = ? OR contact_email = ?) AND is_active = 1");
            $checkStmt->execute([$data['business_name'], $data['contact_email']]);
            if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception("A supplier with this business name or email already exists");
            }

            // Check if email already exists in users table
            $userEmailCheck = $this->conn->prepare("SELECT user_id FROM tbl_users WHERE user_email = ?");
            $userEmailCheck->execute([$data['contact_email']]);
            if ($userEmailCheck->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception("An account with this email already exists");
            }

            $userId = null;
            $tempPassword = null;

            // Create user account for internal suppliers
            if ($data['supplier_type'] === 'internal') {
                // Generate secure random password
                $tempPassword = $this->generateSecurePassword();
                $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);

                // Parse contact person or use business name
                $nameParts = $this->parseContactPersonName($data['contact_person'] ?? $data['business_name']);

                $userSql = "INSERT INTO tbl_users (
                               user_firstName, user_lastName, user_email, user_contact,
                               user_pwd, user_role, force_password_change, account_status, created_at
                           ) VALUES (?, ?, ?, ?, ?, 'supplier', 1, 'active', NOW())";

                $userStmt = $this->conn->prepare($userSql);
                $userStmt->execute([
                    $nameParts['firstName'],
                    $nameParts['lastName'],
                    $data['contact_email'],
                    $data['contact_number'],
                    $hashedPassword
                ]);

                $userId = $this->conn->lastInsertId();
            }

            // Insert supplier with existing table structure
            $sql = "INSERT INTO tbl_suppliers (
                        user_id, supplier_type, business_name, contact_number, contact_email,
                        contact_person, business_address, agreement_signed, registration_docs,
                        business_description, specialty_category, is_active, is_verified,
                        created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())";

            $stmt = $this->conn->prepare($sql);

            $agreementSigned = isset($data['agreement_signed']) ? (int)$data['agreement_signed'] : 0;
            $registrationDocs = isset($data['registration_docs']) ? json_encode($data['registration_docs']) : null;
            $isVerified = isset($data['is_verified']) ? (int)$data['is_verified'] : 0;

            $stmt->execute([
                $userId,
                $data['supplier_type'],
                $data['business_name'],
                $data['contact_number'],
                $data['contact_email'],
                $data['contact_person'] ?? null,
                $data['business_address'] ?? null,
                $agreementSigned,
                $registrationDocs,
                $data['business_description'] ?? null,
                $data['specialty_category'] ?? null,
                $isVerified
            ]);

            $supplierId = $this->conn->lastInsertId();

            // Store temporary credentials for email sending
            if ($tempPassword && $userId) {
                $credentialSql = "INSERT INTO tbl_supplier_credentials (
                                     supplier_id, user_id, temp_password_hash, expires_at, created_at
                                 ) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())";

                $credentialStmt = $this->conn->prepare($credentialSql);
                $credentialStmt->execute([$supplierId, $userId, password_hash($tempPassword, PASSWORD_DEFAULT)]);
            }

            // Log supplier creation activity
            $this->logSupplierActivity($supplierId, 'created', 'Supplier account created by admin', null, [
                'admin_id' => $data['admin_id'] ?? null,
                'supplier_type' => $data['supplier_type']
            ]);

            // Handle document uploads if provided
            if (isset($data['documents']) && is_array($data['documents'])) {
                foreach ($data['documents'] as $document) {
                    $this->saveSupplierDocument($supplierId, $document, $data['admin_id'] ?? null);
                }
            }

            $this->conn->commit();

            // Send welcome email for internal suppliers
            $emailSent = false;
            if ($data['supplier_type'] === 'internal' && $tempPassword) {
                $emailSent = $this->sendSupplierWelcomeEmail(
                    $data['contact_email'],
                    $data['contact_person'] ?? $data['business_name'],
                    $tempPassword,
                    $supplierId
                );
            }

            // Determine onboarding status based on created supplier
            $onboardingStatus = 'pending';
            if ($isVerified) {
                $onboardingStatus = 'active';
            } elseif ($agreementSigned) {
                $onboardingStatus = 'verified';
            }

            return json_encode([
                "status" => "success",
                "message" => "Supplier created successfully" . ($emailSent ? " and welcome email sent" : ""),
                "supplier_id" => $supplierId,
                "user_id" => $userId,
                "onboarding_status" => $onboardingStatus,
                "email_sent" => $emailSent,
                "credentials" => $tempPassword ? [
                    "username" => $data['contact_email'], // Use email as username
                    "password" => $tempPassword,
                    "email_sent" => $emailSent
                ] : null
            ]);

        } catch (Exception $e) {
            $this->conn->rollback();
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Generate secure random password
    private function generateSecurePassword($length = 12) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $charLength = strlen($chars);

        // Ensure at least one of each type
        $password .= $chars[random_int(0, 25)]; // lowercase
        $password .= $chars[random_int(26, 51)]; // uppercase
        $password .= $chars[random_int(52, 61)]; // number
        $password .= $chars[random_int(62, $charLength - 1)]; // special

        // Fill the rest randomly
        for ($i = 4; $i < $length; $i++) {
            $password .= $chars[random_int(0, $charLength - 1)];
        }

        return str_shuffle($password);
    }

    // Parse contact person name into first and last name
    private function parseContactPersonName($fullName) {
        $nameParts = explode(' ', trim($fullName));
        $firstName = $nameParts[0];
        $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : 'Account';

        return [
            'firstName' => $firstName,
            'lastName' => $lastName
        ];
    }

    // Log supplier activity
    private function logSupplierActivity($supplierId, $activityType, $description, $relatedId = null, $metadata = null) {
        try {
            $sql = "INSERT INTO tbl_supplier_activity (
                        supplier_id, activity_type, activity_description, related_id, metadata,
                        ip_address, user_agent, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $supplierId,
                $activityType,
                $description,
                $relatedId,
                $metadata ? json_encode($metadata) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Failed to log supplier activity: " . $e->getMessage());
        }
    }

    // Save supplier document
    private function saveSupplierDocument($supplierId, $documentData, $uploadedBy) {
        try {
            if (!isset($documentData['file_name']) || !isset($documentData['file_path'])) {
                return false;
            }

            $sql = "INSERT INTO tbl_supplier_documents (
                        supplier_id, document_type, document_title, file_name, file_path,
                        file_size, file_type, uploaded_by, is_active, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $supplierId,
                $documentData['document_type'] ?? 'other',
                $documentData['document_title'] ?? 'Uploaded Document',
                $documentData['file_name'],
                $documentData['file_path'],
                $documentData['file_size'] ?? 0,
                $documentData['file_type'] ?? null,
                $uploadedBy
            ]);

            return true;
        } catch (Exception $e) {
            error_log("Failed to save supplier document: " . $e->getMessage());
            return false;
        }
    }

        // Send supplier welcome email with credentials
    private function sendSupplierWelcomeEmail($email, $supplierName, $tempPassword, $supplierId) {
        try {
            require_once 'vendor/autoload.php';

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            // Use existing SMTP configuration from auth.php
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'aizelartunlock@gmail.com';
            $mail->Password = 'nhueuwnriexqdbpt';
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('aizelartunlock@gmail.com', 'Event Planning System');
            $mail->addAddress($email, $supplierName);

            $mail->isHTML(true);
            $mail->Subject = '🎉 Welcome to Event Planning System – Supplier Portal Access Granted';

            // Generate professional welcome email
            $portalUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/supplier/login';

            $mail->Body = $this->generateSupplierWelcomeEmailTemplate(
                $supplierName,
                $email,
                $tempPassword,
                $portalUrl
            );

            $mail->AltBody = $this->generateSupplierWelcomeEmailText(
                $supplierName,
                $email,
                $tempPassword,
                $portalUrl
            );

            $success = $mail->send();

            // Log email activity
            $this->logEmailActivity(
                $email,
                $supplierName,
                'supplier_welcome',
                $mail->Subject,
                $success ? 'sent' : 'failed',
                $success ? null : $mail->ErrorInfo,
                null,
                $supplierId
            );

            return $success;

        } catch (Exception $e) {
            error_log("Failed to send supplier welcome email: " . $e->getMessage());

            // Log failed email attempt
            $this->logEmailActivity(
                $email,
                $supplierName,
                'supplier_welcome',
                'Welcome Email Failed',
                'failed',
                $e->getMessage(),
                null,
                $supplierId
            );

            return false;
        }
    }

    // Generate HTML email template for supplier welcome
    private function generateSupplierWelcomeEmailTemplate($supplierName, $email, $tempPassword, $portalUrl) {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    margin: 0;
                    padding: 0;
                    background-color: #f8fafc;
                    line-height: 1.6;
                }
                .container {
                    max-width: 600px;
                    margin: 0 auto;
                    background-color: #ffffff;
                    border-radius: 12px;
                    overflow: hidden;
                    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                }
                .header {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 40px 30px;
                    text-align: center;
                }
                .header h1 {
                    margin: 0;
                    font-size: 28px;
                    font-weight: 600;
                }
                .header p {
                    margin: 10px 0 0 0;
                    opacity: 0.9;
                    font-size: 16px;
                }
                .content {
                    padding: 40px 30px;
                }
                .welcome-message {
                    font-size: 18px;
                    color: #2d3748;
                    margin-bottom: 30px;
                }
                .credentials-box {
                    background-color: #f7fafc;
                    border: 2px solid #e2e8f0;
                    border-radius: 8px;
                    padding: 24px;
                    margin: 30px 0;
                }
                .credentials-title {
                    font-size: 16px;
                    font-weight: 600;
                    color: #2d3748;
                    margin-bottom: 16px;
                }
                .credential-item {
                    margin-bottom: 12px;
                }
                .credential-label {
                    font-weight: 500;
                    color: #4a5568;
                }
                .credential-value {
                    font-family: "Monaco", "Menlo", monospace;
                    background-color: #edf2f7;
                    padding: 8px 12px;
                    border-radius: 4px;
                    border: 1px solid #cbd5e0;
                    font-size: 14px;
                    color: #2d3748;
                    margin-top: 4px;
                    word-break: break-all;
                }
                .features-list {
                    background-color: #f0fff4;
                    border-left: 4px solid #48bb78;
                    padding: 20px;
                    margin: 30px 0;
                }
                .features-list h3 {
                    color: #2f855a;
                    margin-top: 0;
                    font-size: 16px;
                }
                .features-list ul {
                    margin: 12px 0;
                    padding-left: 20px;
                }
                .features-list li {
                    color: #2d3748;
                    margin-bottom: 8px;
                }
                .security-notice {
                    background-color: #fffbeb;
                    border: 1px solid #f6e05e;
                    border-radius: 8px;
                    padding: 20px;
                    margin: 30px 0;
                }
                .security-notice h3 {
                    color: #d69e2e;
                    margin-top: 0;
                    font-size: 16px;
                }
                .security-notice ol {
                    margin: 12px 0;
                    padding-left: 20px;
                }
                .security-notice li {
                    color: #744210;
                    margin-bottom: 8px;
                }
                .cta-button {
                    display: inline-block;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    text-decoration: none;
                    padding: 14px 28px;
                    border-radius: 8px;
                    font-weight: 600;
                    margin: 20px 0;
                    text-align: center;
                }
                .footer {
                    background-color: #2d3748;
                    color: #a0aec0;
                    padding: 30px;
                    text-align: center;
                    font-size: 14px;
                }
                .footer p {
                    margin: 8px 0;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🎉 Welcome to Event Planning System</h1>
                    <p>Supplier Portal Access Granted</p>
                </div>

                <div class="content">
                    <div class="welcome-message">
                        Dear <strong>' . htmlspecialchars($supplierName) . '</strong>,
                    </div>

                    <p>Congratulations! You are now officially onboarded as a partnered supplier of <strong>Event Planning System</strong>.</p>

                    <div class="features-list">
                        <h3>🚀 As part of our supplier network, you can now:</h3>
                        <ul>
                            <li>Manage your service tiers and packages</li>
                            <li>View assigned events and bookings</li>
                            <li>Upload proposals and portfolio items</li>
                            <li>Track payment schedules and earnings</li>
                            <li>Receive client feedback and ratings</li>
                            <li>Access comprehensive analytics dashboard</li>
                        </ul>
                    </div>

                    <div class="credentials-box">
                        <div class="credentials-title">🔐 Your Login Credentials:</div>
                        <div class="credential-item">
                            <div class="credential-label">Username/Email:</div>
                            <div class="credential-value">' . htmlspecialchars($email) . '</div>
                        </div>
                        <div class="credential-item">
                            <div class="credential-label">Temporary Password:</div>
                            <div class="credential-value">' . htmlspecialchars($tempPassword) . '</div>
                        </div>
                    </div>

                    <div class="security-notice">
                        <h3>🔒 Important Security Steps:</h3>
                        <ol>
                            <li>Log in to the Supplier Portal using the link below</li>
                            <li><strong>Change your password immediately</strong> after first login</li>
                            <li>Keep your credentials private and secure</li>
                            <li>Enable two-factor authentication if available</li>
                        </ol>
                    </div>

                    <div style="text-align: center;">
                        <a href="' . htmlspecialchars($portalUrl) . '" class="cta-button">
                            Access Supplier Portal →
                        </a>
                    </div>

                    <p><strong>Important:</strong> Do not reply to this message. If you did not expect this access or have any questions, please contact our admin team immediately.</p>
                </div>

                <div class="footer">
                    <p><strong>Event Planning System</strong></p>
                    <p>Admin Team | support@eventplanning.com</p>
                    <p>This is an automated message. Please do not reply.</p>
                </div>
            </div>
        </body>
        </html>';
    }

    // Generate plain text email for supplier welcome
    private function generateSupplierWelcomeEmailText($supplierName, $email, $tempPassword, $portalUrl) {
        return "
WELCOME TO EVENT PLANNING SYSTEM - SUPPLIER PORTAL ACCESS GRANTED

Dear {$supplierName},

Congratulations! You are now officially onboarded as a partnered supplier of Event Planning System.

As part of our supplier network, you can now:
- Manage your service tiers and packages
- View assigned events and bookings
- Upload proposals and portfolio items
- Track payment schedules and earnings
- Receive client feedback and ratings
- Access comprehensive analytics dashboard

YOUR LOGIN CREDENTIALS:
Username/Email: {$email}
Temporary Password: {$tempPassword}

IMPORTANT SECURITY STEPS:
1. Log in to the Supplier Portal at: {$portalUrl}
2. Change your password immediately after first login
3. Keep your credentials private and secure
4. Enable two-factor authentication if available

Do not reply to this message. If you did not expect this access or have any questions, please contact our admin team immediately.

Regards,
Event Planning System Admin Team
support@eventplanning.com

This is an automated message. Please do not reply.
        ";
    }

    // Log email activity
    private function logEmailActivity($recipientEmail, $recipientName, $emailType, $subject, $status, $errorMessage = null, $userId = null, $supplierId = null) {
        try {
            $sql = "INSERT INTO tbl_email_logs (
                        recipient_email, recipient_name, email_type, subject, sent_status,
                        sent_at, error_message, related_user_id, related_supplier_id, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $recipientEmail,
                $recipientName,
                $emailType,
                $subject,
                $status,
                $status === 'sent' ? date('Y-m-d H:i:s') : null,
                $errorMessage,
                $userId,
                $supplierId
            ]);
        } catch (Exception $e) {
            error_log("Failed to log email activity: " . $e->getMessage());
        }
    }

    // Get all suppliers with pagination and filtering (Admin view)
    public function getAllSuppliers($page = 1, $limit = 20, $filters = []) {
        try {
            $offset = ($page - 1) * $limit;

            $whereClauses = ["s.is_active = 1"];
            $params = [];

            // Apply filters
            if (!empty($filters['supplier_type'])) {
                $whereClauses[] = "s.supplier_type = ?";
                $params[] = $filters['supplier_type'];
            }

            if (!empty($filters['specialty_category'])) {
                $whereClauses[] = "s.specialty_category = ?";
                $params[] = $filters['specialty_category'];
            }

            if (!empty($filters['is_verified'])) {
                $whereClauses[] = "s.is_verified = ?";
                $params[] = (int)$filters['is_verified'];
            }

            if (!empty($filters['onboarding_status'])) {
                switch ($filters['onboarding_status']) {
                    case 'active':
                        $whereClauses[] = "s.is_verified = 1";
                        break;
                    case 'verified':
                        $whereClauses[] = "s.is_verified = 0 AND s.agreement_signed = 1";
                        break;
                    case 'pending':
                        $whereClauses[] = "s.is_verified = 0 AND s.agreement_signed = 0";
                        break;
                    case 'documents_uploaded':
                        $whereClauses[] = "s.is_verified = 0 AND s.agreement_signed = 0";
                        break;
                    case 'suspended':
                        $whereClauses[] = "s.is_active = 0";
                        break;
                }
            }

            if (!empty($filters['search'])) {
                $whereClauses[] = "(s.business_name LIKE ? OR s.contact_person LIKE ? OR s.contact_email LIKE ?)";
                $searchTerm = "%" . $filters['search'] . "%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            $whereSQL = "WHERE " . implode(" AND ", $whereClauses);

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM tbl_suppliers s $whereSQL";
            $countStmt = $this->conn->prepare($countSql);
            $countStmt->execute($params);
            $totalResult = $countStmt->fetch();
            $total = $totalResult['total'];

            // Get suppliers with basic information first (simplified query for debugging)
            $sql = "SELECT s.*
                    FROM tbl_suppliers s
                    $whereSQL
                    ORDER BY s.created_at DESC
                    LIMIT ? OFFSET ?";

            $stmt = $this->conn->prepare($sql);
            $params[] = $limit;
            $params[] = $offset;

            // Debug logging (commented out for production)
            // error_log("getAllSuppliers SQL: " . $sql);
            // error_log("getAllSuppliers params: " . print_r($params, true));

            $stmt->execute($params);

            $suppliers = [];
            while ($row = $stmt->fetch()) {
                // Parse registration docs
                $row['registration_docs'] = $row['registration_docs'] ? json_decode($row['registration_docs'], true) : [];

                // Set default values for missing fields
                $row['total_offers'] = 0;
                $row['total_bookings'] = 0;
                $row['total_ratings'] = $row['total_ratings'] ?? 0;
                $row['total_documents'] = 0;

                // Set default onboarding status based on existing fields
                if ($row['is_verified']) {
                    $row['onboarding_status'] = 'active';
                } elseif ($row['agreement_signed']) {
                    $row['onboarding_status'] = 'verified';
                } else {
                    $row['onboarding_status'] = 'pending';
                }

                // Set default values for other missing fields
                $row['last_activity'] = $row['updated_at'] ?? $row['created_at'];

                $suppliers[] = $row;
            }

            // Debug logging (commented out for production)
            // error_log("getAllSuppliers found " . count($suppliers) . " suppliers");
            // if (count($suppliers) > 0) {
            //     error_log("First supplier: " . print_r($suppliers[0], true));
            // }

            return json_encode([
                "status" => "success",
                "suppliers" => $suppliers,
                "pagination" => [
                    "current_page" => $page,
                    "total_pages" => ceil($total / $limit),
                    "total_records" => $total,
                    "limit" => $limit
                ]
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // New method specifically for event builder supplier selection
    public function getSuppliersForEventBuilder($page = 1, $limit = 100, $filters = []) {
        try {
            $offset = ($page - 1) * $limit;

            $whereClauses = ["s.is_active = 1", "s.is_verified = 1"];
            $params = [];

            // Apply filters
            if (!empty($filters['specialty_category'])) {
                $whereClauses[] = "s.specialty_category = ?";
                $params[] = $filters['specialty_category'];
            }

            if (!empty($filters['search'])) {
                $whereClauses[] = "(s.business_name LIKE ? OR s.contact_person LIKE ? OR s.contact_email LIKE ?)";
                $searchTerm = "%" . $filters['search'] . "%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            $whereSQL = "WHERE " . implode(" AND ", $whereClauses);

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM tbl_suppliers s $whereSQL";
            $countStmt = $this->conn->prepare($countSql);
            $countStmt->execute($params);
            $totalResult = $countStmt->fetch();
            $total = $totalResult['total'];

            // Get suppliers with offers for pricing tiers
            $sql = "SELECT
                        s.supplier_id,
                        s.business_name,
                        s.specialty_category,
                        s.contact_email,
                        s.contact_number,
                        s.contact_person,
                        s.business_description,
                        s.rating_average,
                        s.total_ratings,
                        s.is_active,
                        s.is_verified,
                        s.created_at,
                        s.updated_at
                    FROM tbl_suppliers s
                    $whereSQL
                    ORDER BY s.business_name ASC
                    LIMIT ? OFFSET ?";

            $stmt = $this->conn->prepare($sql);
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);

            $suppliers = [];
            while ($row = $stmt->fetch()) {
                // Get pricing tiers from offers
                $offersSql = "SELECT
                                offer_title,
                                price_min,
                                price_max,
                                service_category,
                                package_size
                              FROM tbl_supplier_offers
                              WHERE supplier_id = ? AND is_active = 1
                              ORDER BY price_min ASC";

                $offersStmt = $this->conn->prepare($offersSql);
                $offersStmt->execute([$row['supplier_id']]);
                $offers = $offersStmt->fetchAll();

                // Format pricing tiers
                $pricingTiers = [];
                foreach ($offers as $offer) {
                    $pricingTiers[] = [
                        'tier_name' => $offer['offer_title'],
                        'tier_price' => (float)$offer['price_min'],
                        'tier_description' => $offer['service_category'] . ' - ' . $offer['package_size']
                    ];
                }

                // Format supplier data for frontend
                $formattedSupplier = [
                    'supplier_id' => $row['supplier_id'],
                    'supplier_name' => $row['business_name'],
                    'supplier_category' => $row['specialty_category'],
                    'supplier_email' => $row['contact_email'],
                    'supplier_phone' => $row['contact_number'],
                    'supplier_status' => $row['is_active'] ? 'active' : 'inactive',
                    'pricing_tiers' => $pricingTiers,
                    'rating_average' => (float)$row['rating_average'],
                    'total_ratings' => (int)$row['total_ratings'],
                    'business_description' => $row['business_description'],
                    'contact_person' => $row['contact_person']
                ];

                $suppliers[] = $formattedSupplier;
            }

            return json_encode([
                "status" => "success",
                "data" => [
                    "suppliers" => $suppliers,
                    "pagination" => [
                        "current_page" => $page,
                        "total_pages" => ceil($total / $limit),
                        "total_records" => $total,
                        "per_page" => $limit
                    ]
                ]
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => "Error fetching suppliers: " . $e->getMessage()]);
        }
    }

    // Get supplier by ID with complete details (Admin view)
    public function getSupplierById($supplierId) {
        try {
            $sql = "SELECT s.*,
                           u.user_firstName, u.user_lastName, u.user_email as user_account_email
                    FROM tbl_suppliers s
                    LEFT JOIN tbl_users u ON s.user_id = u.user_id
                    WHERE s.supplier_id = ? AND s.is_active = 1";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$supplierId]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$supplier) {
                return json_encode(["status" => "error", "message" => "Supplier not found"]);
            }

            $supplier['registration_docs'] = $supplier['registration_docs'] ? json_decode($supplier['registration_docs'], true) : [];

            // Get supplier offers with subcomponents
            $offersSql = "SELECT so.*,
                                 GROUP_CONCAT(CONCAT(sc.component_title, '|', sc.component_description, '|', sc.is_customizable) SEPARATOR ';;') as subcomponents
                          FROM tbl_supplier_offers so
                          LEFT JOIN tbl_offer_subcomponents sc ON so.offer_id = sc.offer_id AND sc.is_active = 1
                          WHERE so.supplier_id = ? AND so.is_active = 1
                          GROUP BY so.offer_id
                          ORDER BY so.tier_level ASC, so.created_at DESC";
            $offersStmt = $this->conn->prepare($offersSql);
            $offersStmt->execute([$supplierId]);

            $offers = [];
            while ($offer = $offersStmt->fetch()) {
                $offer['offer_attachments'] = $offer['offer_attachments'] ? json_decode($offer['offer_attachments'], true) : [];

                // Parse subcomponents
                $subcomponents = [];
                if ($offer['subcomponents']) {
                    $components = explode(';;', $offer['subcomponents']);
                    foreach ($components as $comp) {
                        $parts = explode('|', $comp);
                        if (count($parts) >= 3) {
                            $subcomponents[] = [
                                'title' => $parts[0],
                                'description' => $parts[1],
                                'is_customizable' => (bool)$parts[2]
                            ];
                        }
                    }
                }
                $offer['subcomponents'] = $subcomponents;
                unset($offer['subcomponents_raw']);

                $offers[] = $offer;
            }
            $supplier['offers'] = $offers;

            // Get supplier documents
            $docsSql = "SELECT * FROM tbl_supplier_documents WHERE supplier_id = ? AND is_active = 1 ORDER BY created_at DESC";
            $docsStmt = $this->conn->prepare($docsSql);
            $docsStmt->execute([$supplierId]);

            $documents = [];
            while ($doc = $docsStmt->fetch()) {
                $documents[] = $doc;
            }
            $supplier['documents'] = $documents;

            // Get recent ratings with event details
            $ratingsSql = "SELECT sr.*, u.user_firstName, u.user_lastName, e.event_title, ec.component_title
                          FROM tbl_supplier_ratings sr
                          LEFT JOIN tbl_users u ON sr.client_id = u.user_id
                          LEFT JOIN tbl_events e ON sr.event_id = e.event_id
                          LEFT JOIN tbl_event_components ec ON sr.event_component_id = ec.event_component_id
                          WHERE sr.supplier_id = ? AND sr.is_public = 1
                          ORDER BY sr.created_at DESC
                          LIMIT 10";
            $ratingsStmt = $this->conn->prepare($ratingsSql);
            $ratingsStmt->execute([$supplierId]);

            $ratings = [];
            while ($rating = $ratingsStmt->fetch()) {
                $rating['feedback_attachments'] = $rating['feedback_attachments'] ? json_decode($rating['feedback_attachments'], true) : [];
                $ratings[] = $rating;
            }
            $supplier['recent_ratings'] = $ratings;

            // Get supplier activity log
            $activitySql = "SELECT * FROM tbl_supplier_activity WHERE supplier_id = ? ORDER BY created_at DESC LIMIT 20";
            $activityStmt = $this->conn->prepare($activitySql);
            $activityStmt->execute([$supplierId]);

            $activities = [];
            while ($activity = $activityStmt->fetch()) {
                $activity['metadata'] = $activity['metadata'] ? json_decode($activity['metadata'], true) : [];
                $activities[] = $activity;
            }
            $supplier['recent_activities'] = $activities;

            return json_encode([
                "status" => "success",
                "supplier" => $supplier
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Update supplier (Admin only)
    public function updateSupplier($supplierId, $data) {
        try {
            $this->conn->beginTransaction();

            // Check if supplier exists
            $checkStmt = $this->conn->prepare("SELECT user_id FROM tbl_suppliers WHERE supplier_id = ? AND is_active = 1");
            $checkStmt->execute([$supplierId]);
            $supplierData = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$supplierData) {
                throw new Exception("Supplier not found");
            }

            // Update user account if exists and email is being changed
            if ($supplierData['user_id'] && isset($data['contact_email'])) {
                $userUpdateStmt = $this->conn->prepare("UPDATE tbl_users SET user_email = ? WHERE user_id = ?");
                $userUpdateStmt->execute([$data['contact_email'], $supplierData['user_id']]);
            }

            // Build update query dynamically
            $updateFields = [];
            $params = [];

            $allowedFields = [
                'business_name', 'contact_number', 'contact_email', 'contact_person',
                'business_address', 'agreement_signed', 'business_description',
                'specialty_category', 'is_verified'
            ];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (isset($data['registration_docs'])) {
                $updateFields[] = "registration_docs = ?";
                $params[] = json_encode($data['registration_docs']);
            }

            if (empty($updateFields)) {
                throw new Exception("No fields to update");
            }

            $updateFields[] = "updated_at = NOW()";
            $params[] = $supplierId;

            $sql = "UPDATE tbl_suppliers SET " . implode(", ", $updateFields) . " WHERE supplier_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Supplier updated successfully"
            ]);

        } catch (Exception $e) {
            $this->conn->rollback();
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Delete supplier (Admin only - soft delete)
    public function deleteSupplier($supplierId) {
        try {
            $stmt = $this->conn->prepare("UPDATE tbl_suppliers SET is_active = 0, updated_at = NOW() WHERE supplier_id = ?");
            $stmt->execute([$supplierId]);

            if ($stmt->rowCount() === 0) {
                return json_encode(["status" => "error", "message" => "Supplier not found"]);
            }

            return json_encode([
                "status" => "success",
                "message" => "Supplier deleted successfully"
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Get supplier categories for filtering (Admin use)
    public function getSupplierCategories() {
        try {
            $sql = "SELECT DISTINCT specialty_category
                    FROM tbl_suppliers
                    WHERE specialty_category IS NOT NULL AND is_active = 1
                    ORDER BY specialty_category";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            $categories = [];
            while ($row = $stmt->fetch()) {
                $categories[] = $row['specialty_category'];
            }

            return json_encode([
                "status" => "success",
                "categories" => $categories
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Get supplier statistics for admin dashboard
    public function getSupplierStats() {
        try {
            $sql = "SELECT
                        COUNT(*) as total_suppliers,
                        SUM(CASE WHEN supplier_type = 'internal' THEN 1 ELSE 0 END) as internal_suppliers,
                        SUM(CASE WHEN supplier_type = 'external' THEN 1 ELSE 0 END) as external_suppliers,
                        SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified_suppliers,
                        AVG(rating_average) as overall_avg_rating
                    FROM tbl_suppliers
                    WHERE is_active = 1";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $stats = $stmt->fetch();

            // Get category breakdown
            $categorySql = "SELECT specialty_category, COUNT(*) as count
                           FROM tbl_suppliers
                           WHERE is_active = 1 AND specialty_category IS NOT NULL
                           GROUP BY specialty_category
                           ORDER BY count DESC";

            $categoryStmt = $this->conn->prepare($categorySql);
            $categoryStmt->execute();

            $categoryBreakdown = [];
            while ($row = $categoryStmt->fetch()) {
                $categoryBreakdown[] = $row;
            }

            $stats['category_breakdown'] = $categoryBreakdown;
            $stats['overall_avg_rating'] = round($stats['overall_avg_rating'], 2);

            return json_encode([
                "status" => "success",
                "stats" => $stats
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // ==================== SUPPLIER DOCUMENT MANAGEMENT ====================

    // Upload supplier document
    public function uploadSupplierDocument($supplierId, $file, $documentType, $title, $uploadedBy) {
        try {
            // Validate inputs
            if (empty($supplierId) || empty($file) || empty($documentType)) {
                throw new Exception("Supplier ID, file, and document type are required");
            }

            // Validate document type
            $allowedTypes = ['dti', 'business_permit', 'contract', 'portfolio', 'certification', 'other'];
            if (!in_array($documentType, $allowedTypes)) {
                throw new Exception("Invalid document type. Allowed: " . implode(', ', $allowedTypes));
            }

            // Validate file
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            $maxFileSize = 10 * 1024 * 1024; // 10MB

            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($fileExtension, $allowedExtensions)) {
                throw new Exception("Invalid file type. Allowed: " . implode(', ', $allowedExtensions));
            }

            if ($file['size'] > $maxFileSize) {
                throw new Exception("File size exceeds 10MB limit");
            }

            // Create upload directory if it doesn't exist
            $uploadDir = "uploads/supplier_documents/{$documentType}/";
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename
            $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
            $filePath = $uploadDir . $fileName;

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new Exception("Failed to upload file");
            }

            // Save to database
            $sql = "INSERT INTO tbl_supplier_documents (
                        supplier_id, document_type, document_title, file_name, file_path,
                        file_size, file_type, uploaded_by, is_active, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $supplierId,
                $documentType,
                $title,
                $fileName,
                $filePath,
                $file['size'],
                $file['type'],
                $uploadedBy
            ]);

            $documentId = $this->conn->lastInsertId();

            // Log activity
            $this->logSupplierActivity($supplierId, 'document_uploaded',
                "Uploaded document: {$title}", $documentId, [
                    'document_type' => $documentType,
                    'file_name' => $fileName,
                    'file_size' => $file['size']
                ]);

            return json_encode([
                "status" => "success",
                "message" => "Document uploaded successfully",
                "document_id" => $documentId,
                "file_path" => $filePath
            ]);

        } catch (Exception $e) {
            // Clean up file if database insert failed
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Get supplier documents
    public function getSupplierDocuments($supplierId, $documentType = null) {
        try {
            $whereClauses = ["sd.supplier_id = ?", "sd.is_active = 1"];
            $params = [$supplierId];

            if ($documentType) {
                $whereClauses[] = "sd.document_type = ?";
                $params[] = $documentType;
            }

            $whereSQL = "WHERE " . implode(" AND ", $whereClauses);

            $sql = "SELECT sd.*, u.user_firstName, u.user_lastName,
                           dt.type_name, dt.description as type_description
                    FROM tbl_supplier_documents sd
                    LEFT JOIN tbl_users u ON sd.uploaded_by = u.user_id
                    LEFT JOIN tbl_document_types dt ON sd.document_type = dt.type_code
                    {$whereSQL}
                    ORDER BY sd.document_type ASC, sd.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);

            $documents = [];
            while ($row = $stmt->fetch()) {
                // Add file URL for download
                $row['file_url'] = $this->generateSecureFileUrl($row['file_path']);
                $row['file_size_formatted'] = $this->formatFileSize($row['file_size']);
                $documents[] = $row;
            }

            return json_encode([
                "status" => "success",
                "documents" => $documents
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Verify supplier document
    public function verifySupplierDocument($documentId, $verifiedBy, $status = 'verified', $notes = null) {
        try {
            $sql = "UPDATE tbl_supplier_documents
                    SET is_verified = ?, verified_by = ?, verified_at = NOW(),
                        verification_notes = ?, updated_at = NOW()
                    WHERE document_id = ?";

            $stmt = $this->conn->prepare($sql);
            $isVerified = $status === 'verified' ? 1 : 0;
            $stmt->execute([$isVerified, $verifiedBy, $notes, $documentId]);

            if ($stmt->rowCount() === 0) {
                throw new Exception("Document not found");
            }

            // Get document and supplier info for activity log
            $docSql = "SELECT sd.*, s.supplier_id FROM tbl_supplier_documents sd
                       JOIN tbl_suppliers s ON sd.supplier_id = s.supplier_id
                       WHERE sd.document_id = ?";
            $docStmt = $this->conn->prepare($docSql);
            $docStmt->execute([$documentId]);
            $document = $docStmt->fetch();

            if ($document) {
                $this->logSupplierActivity($document['supplier_id'], 'document_verified',
                    "Document {$status}: {$document['document_title']}", $documentId, [
                        'status' => $status,
                        'verified_by' => $verifiedBy,
                        'notes' => $notes
                    ]);
            }

            return json_encode([
                "status" => "success",
                "message" => "Document {$status} successfully"
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Get document types for form
    public function getDocumentTypes() {
        try {
            $sql = "SELECT * FROM tbl_document_types
                    WHERE is_active = 1
                    ORDER BY display_order ASC, type_name ASC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            $types = [];
            while ($row = $stmt->fetch()) {
                $row['allowed_extensions'] = json_decode($row['allowed_extensions'], true);
                $types[] = $row;
            }

            return json_encode([
                "status" => "success",
                "document_types" => $types
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Generate secure file URL for document access
    private function generateSecureFileUrl($filePath) {
        // This should generate a secure, time-limited URL
        // For now, return relative path - implement token-based access in production
        return "/" . $filePath;
    }

    // Format file size for display
    private function formatFileSize($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    public function getAllVendors() { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function createPackage($data) {
        try {
            $this->conn->beginTransaction();

            // Validate required fields
            if (empty($data['package_title']) || empty($data['package_price']) || empty($data['guest_capacity']) || empty($data['created_by'])) {
                return json_encode(["status" => "error", "message" => "Package title, price, guest capacity, and creator are required"]);
            }

            // Insert main package
            $sql = "INSERT INTO tbl_packages (package_title, package_description, package_price, guest_capacity, created_by, is_active)
                    VALUES (:title, :description, :price, :capacity, :created_by, 1)";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':title' => $data['package_title'],
                ':description' => $data['package_description'] ?? '',
                ':price' => $data['package_price'],
                ':capacity' => $data['guest_capacity'],
                ':created_by' => $data['created_by']
            ]);

            $packageId = $this->conn->lastInsertId();

            // Insert components if provided
            if (!empty($data['components']) && is_array($data['components'])) {
                foreach ($data['components'] as $index => $component) {
                    if (!empty($component['component_name'])) {
                        $componentSql = "INSERT INTO tbl_package_components (package_id, component_name, component_description, component_price, display_order)
                                        VALUES (:package_id, :name, :description, :price, :order)";
                        $componentStmt = $this->conn->prepare($componentSql);
                        $componentStmt->execute([
                            ':package_id' => $packageId,
                            ':name' => $component['component_name'],
                            ':description' => $component['component_description'] ?? '',
                            ':price' => $component['component_price'] ?? 0,
                            ':order' => $index
                        ]);
                    }
                }
            }

            // Insert freebies if provided
            if (!empty($data['freebies']) && is_array($data['freebies'])) {
                foreach ($data['freebies'] as $index => $freebie) {
                    if (!empty($freebie['freebie_name'])) {
                        $freebieSql = "INSERT INTO tbl_package_freebies (package_id, freebie_name, freebie_description, freebie_value, display_order)
                                      VALUES (:package_id, :name, :description, :value, :order)";
                        $freebieStmt = $this->conn->prepare($freebieSql);
                        $freebieStmt->execute([
                            ':package_id' => $packageId,
                            ':name' => $freebie['freebie_name'],
                            ':description' => $freebie['freebie_description'] ?? '',
                            ':value' => $freebie['freebie_value'] ?? 0,
                            ':order' => $index
                        ]);
                    }
                }
            }

            // Insert event types if provided
            if (!empty($data['event_types']) && is_array($data['event_types'])) {
                foreach ($data['event_types'] as $eventTypeId) {
                    $eventTypeSql = "INSERT INTO tbl_package_event_types (package_id, event_type_id) VALUES (:package_id, :event_type_id)";
                    $eventTypeStmt = $this->conn->prepare($eventTypeSql);
                    $eventTypeStmt->execute([
                        ':package_id' => $packageId,
                        ':event_type_id' => $eventTypeId
                    ]);
                }
            }

            // Insert venues if provided
            if (!empty($data['venues']) && is_array($data['venues'])) {
                foreach ($data['venues'] as $venueId) {
                    $venueSql = "INSERT INTO tbl_package_venues (package_id, venue_id) VALUES (:package_id, :venue_id)";
                    $venueStmt = $this->conn->prepare($venueSql);
                    $venueStmt->execute([
                        ':package_id' => $packageId,
                        ':venue_id' => $venueId
                    ]);
                }
            }

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Package created successfully",
                "package_id" => $packageId
            ]);
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("createPackage error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getAllPackages() {
        try {
            $sql = "SELECT
                        p.package_id,
                        p.package_title,
                        p.package_description,
                        p.package_price,
                        p.guest_capacity,
                        p.created_at,
                        p.is_active,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as created_by_name,
                        u.user_firstName,
                        u.user_lastName,
                        COUNT(DISTINCT pc.component_id) as component_count,
                        COUNT(DISTINCT pf.freebie_id) as freebie_count,
                        COUNT(DISTINCT pv.venue_id) as venue_count
                    FROM tbl_packages p
                    LEFT JOIN tbl_users u ON p.created_by = u.user_id
                    LEFT JOIN tbl_package_components pc ON p.package_id = pc.package_id
                    LEFT JOIN tbl_package_freebies pf ON p.package_id = pf.package_id
                    LEFT JOIN tbl_package_venues pv ON p.package_id = pv.package_id
                    WHERE p.is_active = 1
                    GROUP BY p.package_id
                    ORDER BY p.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // For each package, get components and freebies
            foreach ($packages as &$package) {
                // Get components for inclusions preview
                $componentsSql = "SELECT component_name FROM tbl_package_components WHERE package_id = ? ORDER BY display_order LIMIT 5";
                $componentsStmt = $this->conn->prepare($componentsSql);
                $componentsStmt->execute([$package['package_id']]);
                $components = $componentsStmt->fetchAll(PDO::FETCH_COLUMN);
                $package['inclusions'] = $components;

                // Get freebies
                $freebiesSql = "SELECT freebie_name FROM tbl_package_freebies WHERE package_id = ? ORDER BY display_order LIMIT 5";
                $freebiesStmt = $this->conn->prepare($freebiesSql);
                $freebiesStmt->execute([$package['package_id']]);
                $freebies = $freebiesStmt->fetchAll(PDO::FETCH_COLUMN);
                $package['freebies'] = $freebies;
            }

            return json_encode([
                "status" => "success",
                "packages" => $packages,
                "count" => count($packages)
            ]);
        } catch (Exception $e) {
            error_log("getAllPackages error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getPackageById($packageId) {
        try {
            // Get package basic info
            $sql = "SELECT * FROM tbl_packages WHERE package_id = :package_id AND is_active = 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':package_id' => $packageId]);
            $package = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$package) {
                return json_encode(["status" => "error", "message" => "Package not found"]);
            }

            // Get package components
            $componentsSql = "SELECT * FROM tbl_package_components WHERE package_id = :package_id ORDER BY display_order";
            $componentsStmt = $this->conn->prepare($componentsSql);
            $componentsStmt->execute([':package_id' => $packageId]);
            $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get package freebies
            $freebiesSql = "SELECT * FROM tbl_package_freebies WHERE package_id = :package_id ORDER BY display_order";
            $freebiesStmt = $this->conn->prepare($freebiesSql);
            $freebiesStmt->execute([':package_id' => $packageId]);
            $freebies = $freebiesStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get package venues with their inclusions
            $venuesSql = "SELECT
                            v.venue_id,
                            v.venue_title,
                            v.venue_owner,
                            v.venue_location,
                            v.venue_contact,
                            v.venue_details,
                            v.venue_capacity,
                            v.venue_price,
                            v.venue_type,
                            v.venue_profile_picture,
                            v.venue_cover_photo
                        FROM tbl_package_venues pv
                        JOIN tbl_venue v ON pv.venue_id = v.venue_id
                        WHERE pv.package_id = :package_id AND v.venue_status = 'available'
                        ORDER BY v.venue_title";

            $venuesStmt = $this->conn->prepare($venuesSql);
            $venuesStmt->execute([':package_id' => $packageId]);
            $venues = $venuesStmt->fetchAll(PDO::FETCH_ASSOC);

            // For each venue, get its inclusions and components
            foreach ($venues as &$venue) {
                // Get venue inclusions
                $inclusionsSql = "SELECT * FROM tbl_venue_inclusions WHERE venue_id = :venue_id AND is_active = 1";
                $inclusionsStmt = $this->conn->prepare($inclusionsSql);
                $inclusionsStmt->execute([':venue_id' => $venue['venue_id']]);
                $inclusions = $inclusionsStmt->fetchAll(PDO::FETCH_ASSOC);

                // For each inclusion, get its components
                foreach ($inclusions as &$inclusion) {
                    $componentsSql = "SELECT * FROM tbl_venue_components WHERE inclusion_id = :inclusion_id AND is_active = 1";
                    $componentsStmt = $this->conn->prepare($componentsSql);
                    $componentsStmt->execute([':inclusion_id' => $inclusion['inclusion_id']]);
                    $inclusion['components'] = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
                }

                $venue['inclusions'] = $inclusions;
            }

            // Combine all data
            $packageData = [
                'package_id' => $package['package_id'],
                'package_title' => $package['package_title'],
                'package_description' => $package['package_description'],
                'package_price' => $package['package_price'],
                'guest_capacity' => $package['guest_capacity'],
                'is_active' => $package['is_active'],
                'components' => $components,
                'freebies' => $freebies,
                'venues' => $venues
            ];

            return json_encode([
                "status" => "success",
                "package" => $packageData
            ]);
        } catch (Exception $e) {
            error_log("getPackageById error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getPackageDetails($packageId) {
        try {
            // Get package basic info with creator information
            $sql = "SELECT p.*,
                           u.user_firstName, u.user_lastName,
                           CONCAT(u.user_firstName, ' ', u.user_lastName) as created_by_name
                    FROM tbl_packages p
                    LEFT JOIN tbl_users u ON p.created_by = u.user_id
                    WHERE p.package_id = :package_id AND p.is_active = 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':package_id' => $packageId]);
            $package = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$package) {
                return json_encode(["status" => "error", "message" => "Package not found"]);
            }

            // Get package components/inclusions
            $componentsSql = "SELECT * FROM tbl_package_components WHERE package_id = :package_id ORDER BY display_order";
            $componentsStmt = $this->conn->prepare($componentsSql);
            $componentsStmt->execute([':package_id' => $packageId]);
            $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Transform components into the expected structure for detailed view
            $inclusions = [];
            foreach ($components as $component) {
                $inclusions[] = [
                    'name' => $component['component_name'],
                    'price' => (float)$component['component_price'],
                    'components' => [], // For now, components don't have sub-components in this structure
                ];
            }

            // Get package freebies
            $freebiesSql = "SELECT * FROM tbl_package_freebies WHERE package_id = :package_id ORDER BY display_order";
            $freebiesStmt = $this->conn->prepare($freebiesSql);
            $freebiesStmt->execute([':package_id' => $packageId]);
            $freebiesData = $freebiesStmt->fetchAll(PDO::FETCH_ASSOC);

            $freebies = [];
            foreach ($freebiesData as $freebie) {
                $freebies[] = $freebie['freebie_name'];
            }

            // Get package venues with their inclusions
            $venuesSql = "SELECT
                            v.venue_id,
                            v.venue_title,
                            v.venue_owner,
                            v.venue_location,
                            v.venue_contact,
                            v.venue_details,
                            v.venue_capacity,
                            v.venue_price as total_price,
                            v.venue_type,
                            v.venue_profile_picture,
                            v.venue_cover_photo
                        FROM tbl_package_venues pv
                        JOIN tbl_venue v ON pv.venue_id = v.venue_id
                        WHERE pv.package_id = :package_id AND v.venue_status = 'available'
                        ORDER BY v.venue_title";

            $venuesStmt = $this->conn->prepare($venuesSql);
            $venuesStmt->execute([':package_id' => $packageId]);
            $venues = $venuesStmt->fetchAll(PDO::FETCH_ASSOC);

            // For each venue, get its inclusions and components
            foreach ($venues as &$venue) {
                // Get venue inclusions
                $inclusionsSql = "SELECT * FROM tbl_venue_inclusions WHERE venue_id = :venue_id AND is_active = 1";
                $inclusionsStmt = $this->conn->prepare($inclusionsSql);
                $inclusionsStmt->execute([':venue_id' => $venue['venue_id']]);
                $venueInclusions = $inclusionsStmt->fetchAll(PDO::FETCH_ASSOC);

                // For each inclusion, get its components
                foreach ($venueInclusions as &$inclusion) {
                    $componentsSql = "SELECT * FROM tbl_venue_components WHERE inclusion_id = :inclusion_id AND is_active = 1";
                    $componentsStmt = $this->conn->prepare($componentsSql);
                    $componentsStmt->execute([':inclusion_id' => $inclusion['inclusion_id']]);
                    $inclusion['components'] = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
                }

                $venue['inclusions'] = $venueInclusions;
                $venue['total_price'] = (float)$venue['total_price'];
            }

            // Get event types associated with this package
            $eventTypesSql = "SELECT et.*
                             FROM tbl_package_event_types pet
                             JOIN tbl_event_type et ON pet.event_type_id = et.event_type_id
                             WHERE pet.package_id = :package_id";
            $eventTypesStmt = $this->conn->prepare($eventTypesSql);
            $eventTypesStmt->execute([':package_id' => $packageId]);
            $eventTypes = $eventTypesStmt->fetchAll(PDO::FETCH_ASSOC);

            // Combine all data
            $packageData = [
                'package_id' => (int)$package['package_id'],
                'package_title' => $package['package_title'],
                'package_description' => $package['package_description'],
                'package_price' => (float)$package['package_price'],
                'guest_capacity' => (int)$package['guest_capacity'],
                'created_at' => $package['created_at'],
                'user_firstName' => $package['user_firstName'],
                'user_lastName' => $package['user_lastName'],
                'created_by_name' => $package['created_by_name'],
                'is_active' => (int)$package['is_active'],
                'inclusions' => $inclusions,
                'freebies' => $freebies,
                'venues' => $venues,
                'event_types' => $eventTypes
            ];

            return json_encode([
                "status" => "success",
                "package" => $packageData
            ]);
        } catch (Exception $e) {
            error_log("getPackageDetails error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function updatePackage($data) {
        try {
            $this->conn->beginTransaction();

            // Validate required fields
            if (empty($data['package_id']) || empty($data['package_title']) || empty($data['package_price']) || empty($data['guest_capacity'])) {
                return json_encode(["status" => "error", "message" => "Package ID, title, price, and guest capacity are required"]);
            }

            // Get current package data to check price lock status
            $currentPackageSql = "SELECT package_price, original_price, is_price_locked FROM tbl_packages WHERE package_id = :package_id";
            $currentStmt = $this->conn->prepare($currentPackageSql);
            $currentStmt->execute([':package_id' => $data['package_id']]);
            $currentPackage = $currentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentPackage) {
                return json_encode(["status" => "error", "message" => "Package not found"]);
            }

            // Enforce non-decreasing price rule
            $newPrice = floatval($data['package_price']);
            $currentPrice = floatval($currentPackage['package_price']);
            $isLocked = intval($currentPackage['is_price_locked']);

            if ($isLocked && $newPrice < $currentPrice) {
                return json_encode([
                    "status" => "error",
                    "message" => "Cannot reduce package price. Package prices are locked and can only increase or remain the same.",
                    "current_price" => $currentPrice,
                    "attempted_price" => $newPrice
                ]);
            }

            // Check for overage warnings if components are being updated
            if (isset($data['components'])) {
                $totalComponentCost = 0;
                foreach ($data['components'] as $component) {
                    $totalComponentCost += floatval($component['component_price'] ?? 0);
                }

                if ($totalComponentCost > $newPrice) {
                    $overage = $totalComponentCost - $newPrice;
                    if (!isset($data['confirm_overage']) || !$data['confirm_overage']) {
                        return json_encode([
                            "status" => "warning",
                            "message" => "Budget overage detected: Inclusions total exceeds package price",
                            "package_price" => $newPrice,
                            "inclusions_total" => $totalComponentCost,
                            "overage_amount" => $overage,
                            "requires_confirmation" => true
                        ]);
                    }
                }
            }

            // Update main package
            $sql = "UPDATE tbl_packages SET
                        package_title = :title,
                        package_description = :description,
                        package_price = :price,
                        guest_capacity = :capacity,
                        is_price_locked = 1,
                        price_lock_date = CASE
                            WHEN is_price_locked = 0 THEN CURRENT_TIMESTAMP
                            ELSE price_lock_date
                        END,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE package_id = :package_id";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':title' => $data['package_title'],
                ':description' => $data['package_description'] ?? '',
                ':price' => $newPrice,
                ':capacity' => $data['guest_capacity'],
                ':package_id' => $data['package_id']
            ]);

            // Update components - delete existing and insert new ones
            if (isset($data['components'])) {
                // Delete existing components
                $deleteComponentsSql = "DELETE FROM tbl_package_components WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteComponentsSql);
                $deleteStmt->execute([':package_id' => $data['package_id']]);

                // Insert new components
                if (is_array($data['components'])) {
                    foreach ($data['components'] as $index => $component) {
                        if (!empty($component['component_name'])) {
                            $componentSql = "INSERT INTO tbl_package_components (package_id, component_name, component_description, component_price, display_order)
                                            VALUES (:package_id, :name, :description, :price, :order)";
                            $componentStmt = $this->conn->prepare($componentSql);
                            $componentStmt->execute([
                                ':package_id' => $data['package_id'],
                                ':name' => $component['component_name'],
                                ':description' => $component['component_description'] ?? '',
                                ':price' => $component['component_price'] ?? 0,
                                ':order' => $index
                            ]);
                        }
                    }
                }
            }

            // Update freebies - delete existing and insert new ones
            if (isset($data['freebies'])) {
                // Delete existing freebies
                $deleteFreebiesSql = "DELETE FROM tbl_package_freebies WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteFreebiesSql);
                $deleteStmt->execute([':package_id' => $data['package_id']]);

                // Insert new freebies
                if (is_array($data['freebies'])) {
                    foreach ($data['freebies'] as $index => $freebie) {
                        if (!empty($freebie['freebie_name'])) {
                            $freebieSql = "INSERT INTO tbl_package_freebies (package_id, freebie_name, freebie_description, freebie_value, display_order)
                                          VALUES (:package_id, :name, :description, :value, :order)";
                            $freebieStmt = $this->conn->prepare($freebieSql);
                            $freebieStmt->execute([
                                ':package_id' => $data['package_id'],
                                ':name' => $freebie['freebie_name'],
                                ':description' => $freebie['freebie_description'] ?? '',
                                ':value' => $freebie['freebie_value'] ?? 0,
                                ':order' => $index
                            ]);
                        }
                    }
                }
            }

            // Update event types
            if (isset($data['event_types'])) {
                // Delete existing event types
                $deleteEventTypesSql = "DELETE FROM tbl_package_event_types WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteEventTypesSql);
                $deleteStmt->execute([':package_id' => $data['package_id']]);

                // Insert new event types
                if (is_array($data['event_types'])) {
                    foreach ($data['event_types'] as $eventTypeId) {
                        $eventTypeSql = "INSERT INTO tbl_package_event_types (package_id, event_type_id) VALUES (:package_id, :event_type_id)";
                        $eventTypeStmt = $this->conn->prepare($eventTypeSql);
                        $eventTypeStmt->execute([
                            ':package_id' => $data['package_id'],
                            ':event_type_id' => $eventTypeId
                        ]);
                    }
                }
            }

            // Update venues
            if (isset($data['venues'])) {
                // Delete existing venues
                $deleteVenuesSql = "DELETE FROM tbl_package_venues WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteVenuesSql);
                $deleteStmt->execute([':package_id' => $data['package_id']]);

                // Insert new venues
                if (is_array($data['venues'])) {
                    foreach ($data['venues'] as $venueId) {
                        $venueSql = "INSERT INTO tbl_package_venues (package_id, venue_id) VALUES (:package_id, :venue_id)";
                        $venueStmt = $this->conn->prepare($venueSql);
                        $venueStmt->execute([
                            ':package_id' => $data['package_id'],
                            ':venue_id' => $venueId
                        ]);
                    }
                }
            }

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Package updated successfully"
            ]);
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("updatePackage error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function deletePackage($packageId) {
        try {
            // Check if package exists
            $checkSql = "SELECT package_id FROM tbl_packages WHERE package_id = :package_id";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->execute([':package_id' => $packageId]);

            if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
                return json_encode(["status" => "error", "message" => "Package not found"]);
            }

            // Check if package is being used in any events
            $eventCheckSql = "SELECT COUNT(*) as event_count FROM tbl_events WHERE package_id = :package_id";
            $eventCheckStmt = $this->conn->prepare($eventCheckSql);
            $eventCheckStmt->execute([':package_id' => $packageId]);
            $eventCount = $eventCheckStmt->fetch(PDO::FETCH_ASSOC)['event_count'];

            if ($eventCount > 0) {
                // Soft delete - mark as inactive instead of hard delete
                $softDeleteSql = "UPDATE tbl_packages SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE package_id = :package_id";
                $softDeleteStmt = $this->conn->prepare($softDeleteSql);
                $softDeleteStmt->execute([':package_id' => $packageId]);

                return json_encode([
                    "status" => "success",
                    "message" => "Package deactivated successfully (it was being used in events)"
                ]);
            } else {
                // Hard delete - remove completely
                $this->conn->beginTransaction();

                // Delete related records first
                $deleteComponentsSql = "DELETE FROM tbl_package_components WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteComponentsSql);
                $deleteStmt->execute([':package_id' => $packageId]);

                $deleteFreebiesSql = "DELETE FROM tbl_package_freebies WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteFreebiesSql);
                $deleteStmt->execute([':package_id' => $packageId]);

                $deleteEventTypesSql = "DELETE FROM tbl_package_event_types WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteEventTypesSql);
                $deleteStmt->execute([':package_id' => $packageId]);

                $deleteVenuesSql = "DELETE FROM tbl_package_venues WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteVenuesSql);
                $deleteStmt->execute([':package_id' => $packageId]);

                // Delete the main package
                $deleteSql = "DELETE FROM tbl_packages WHERE package_id = :package_id";
                $deleteStmt = $this->conn->prepare($deleteSql);
                $deleteStmt->execute([':package_id' => $packageId]);

                $this->conn->commit();

                return json_encode([
                    "status" => "success",
                    "message" => "Package deleted successfully"
                ]);
            }
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            error_log("deletePackage error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getEventTypes() {
        try {
            $sql = "SELECT event_type_id, event_name, event_description FROM tbl_event_type ORDER BY event_name";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $eventTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return json_encode(["status" => "success", "event_types" => $eventTypes]);
        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getPackagesByEventType($eventTypeId) {
        try {
            error_log("getPackagesByEventType called with eventTypeId: " . $eventTypeId);

            // Enhanced query to get packages with venue and component information
            // If no specific event type relationships exist, return all active packages
            $sql = "SELECT DISTINCT p.package_id,
                        p.package_title,
                        p.package_description,
                        p.package_price,
                        p.guest_capacity,
                        p.created_at,
                        p.is_active,
                        COUNT(DISTINCT pc.component_id) as component_count,
                        COUNT(DISTINCT pf.freebie_id) as freebie_count,
                        COUNT(DISTINCT pv.venue_id) as venue_count
                    FROM tbl_packages p
                    LEFT JOIN tbl_package_event_types pet ON p.package_id = pet.package_id
                    LEFT JOIN tbl_package_components pc ON p.package_id = pc.package_id
                    LEFT JOIN tbl_package_freebies pf ON p.package_id = pf.package_id
                    LEFT JOIN tbl_package_venues pv ON p.package_id = pv.package_id
                    WHERE p.is_active = 1
                    AND (
                        pet.event_type_id = ?
                        OR pet.event_type_id IS NULL
                        OR NOT EXISTS (SELECT 1 FROM tbl_package_event_types WHERE package_id = p.package_id)
                    )
                    GROUP BY p.package_id, p.package_title, p.package_description, p.package_price, p.guest_capacity, p.created_at, p.is_active
                    ORDER BY p.package_title";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$eventTypeId]);
            $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Enhance each package with detailed information
            foreach ($packages as &$package) {
                $packageId = $package['package_id'];

                // Get component names for inclusions preview
                $componentsSql = "SELECT component_name FROM tbl_package_components WHERE package_id = ? ORDER BY display_order LIMIT 10";
                $componentsStmt = $this->conn->prepare($componentsSql);
                $componentsStmt->execute([$packageId]);
                $componentNames = $componentsStmt->fetchAll(PDO::FETCH_COLUMN);
                $package['inclusions'] = $componentNames;

                // Get freebie names
                $freebiesSql = "SELECT freebie_name FROM tbl_package_freebies WHERE package_id = ? ORDER BY display_order LIMIT 10";
                $freebiesStmt = $this->conn->prepare($freebiesSql);
                $freebiesStmt->execute([$packageId]);
                $freebieNames = $freebiesStmt->fetchAll(PDO::FETCH_COLUMN);
                $package['freebies'] = $freebieNames;

                // Get venue previews with pricing
                $venuesSql = "SELECT v.venue_id, v.venue_title, v.venue_location, v.venue_capacity,
                                    v.venue_profile_picture, v.venue_cover_photo, v.venue_price,
                                    COALESCE(SUM(vi.inclusion_price), 0) as inclusions_total
                             FROM tbl_package_venues pv
                             JOIN tbl_venue v ON pv.venue_id = v.venue_id
                             LEFT JOIN tbl_venue_inclusions vi ON v.venue_id = vi.venue_id AND vi.is_active = 1
                             WHERE pv.package_id = ? AND v.venue_status = 'available'
                             GROUP BY v.venue_id, v.venue_title, v.venue_location, v.venue_capacity,
                                      v.venue_profile_picture, v.venue_cover_photo, v.venue_price
                             ORDER BY v.venue_title";
                $venuesStmt = $this->conn->prepare($venuesSql);
                $venuesStmt->execute([$packageId]);
                $venues = $venuesStmt->fetchAll(PDO::FETCH_ASSOC);

                $package['venue_previews'] = [];
                $venuePrices = [];

                foreach ($venues as $venue) {
                    // Add to previews
                    $package['venue_previews'][] = [
                        'venue_id' => $venue['venue_id'],
                        'venue_title' => $venue['venue_title'],
                        'venue_location' => $venue['venue_location'],
                        'venue_capacity' => intval($venue['venue_capacity']),
                        'venue_profile_picture' => $venue['venue_profile_picture'],
                        'venue_cover_photo' => $venue['venue_cover_photo'],
                        'venue_price' => floatval($venue['venue_price'])
                    ];

                    // Calculate total venue price (base + inclusions)
                    $totalVenuePrice = floatval($venue['venue_price']) + floatval($venue['inclusions_total']);
                    $venuePrices[] = $totalVenuePrice;
                }

                // Calculate price ranges
                if (!empty($venuePrices)) {
                    $minVenuePrice = min($venuePrices);
                    $maxVenuePrice = max($venuePrices);
                    $packagePrice = floatval($package['package_price']);

                    $package['venue_price_range'] = [
                        'min' => $minVenuePrice,
                        'max' => $maxVenuePrice,
                        'venues' => array_map(function($venue) use ($packageId) {
                            return [
                                'venue_id' => $venue['venue_id'],
                                'venue_title' => $venue['venue_title'],
                                'venue_location' => $venue['venue_location'],
                                'venue_capacity' => intval($venue['venue_capacity']),
                                'venue_profile_picture' => $venue['venue_profile_picture'],
                                'venue_cover_photo' => $venue['venue_cover_photo'],
                                'venue_price' => $venue['venue_price'],
                                'inclusions_total' => $venue['inclusions_total'],
                                'total_venue_price' => strval(floatval($venue['venue_price']) + floatval($venue['inclusions_total']))
                            ];
                        }, $venues)
                    ];

                    $package['total_price_range'] = [
                        'min' => $packagePrice + $minVenuePrice,
                        'max' => $packagePrice + $maxVenuePrice
                    ];
                } else {
                    $package['venue_price_range'] = null;
                    $package['total_price_range'] = null;
                }
            }

            error_log("Returning " . count($packages) . " enhanced packages for eventTypeId {$eventTypeId}");

            return json_encode([
                "status" => "success",
                "packages" => $packages
            ]);
        } catch (Exception $e) {
            error_log("getPackagesByEventType error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getAllEvents() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    e.*,
                    u.user_firstName,
                    u.user_lastName,
                    u.user_email,
                    et.event_name as event_type_name,
                    p.package_title,
                    v.venue_title
                FROM tbl_events e
                LEFT JOIN tbl_users u ON e.user_id = u.user_id
                LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                LEFT JOIN tbl_packages p ON e.package_id = p.package_id
                LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                ORDER BY e.created_at DESC
            ");
            $stmt->execute();
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "events" => $events
            ]);
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch events: " . $e->getMessage()
            ]);
        }
    }
    public function getEvents($adminId) {
        try {
            $sql = "SELECT
                        e.*,
                        CONCAT(c.user_firstName, ' ', c.user_lastName) as client_name,
                        c.user_firstName as client_first_name,
                        c.user_lastName as client_last_name,
                        c.user_suffix as client_suffix,
                        c.user_email as client_email,
                        c.user_contact as client_contact,
                        c.user_pfp as client_pfp,
                        c.user_birthdate as client_birthdate,
                        c.created_at as client_joined_date,
                        CONCAT(a.user_firstName, ' ', a.user_lastName) as admin_name,
                        CONCAT(o.user_firstName, ' ', o.user_lastName) as organizer_name,
                        et.event_name as event_type_name,
                        v.venue_title as venue_name,
                        v.venue_location as venue_location,
                        p.package_title as package_title
                    FROM tbl_events e
                    LEFT JOIN tbl_users c ON e.user_id = c.user_id
                    LEFT JOIN tbl_users a ON e.admin_id = a.user_id
                    LEFT JOIN tbl_users o ON e.organizer_id = o.user_id
                    LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                    LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                    LEFT JOIN tbl_packages p ON e.package_id = p.package_id
                    WHERE e.admin_id = ?
                    ORDER BY e.event_date ASC, e.start_time ASC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$adminId]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "events" => $events,
                "count" => count($events)
            ]);
        } catch (Exception $e) {
            error_log("getEvents error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getClientEvents($userId) { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function checkEventConflicts($eventDate, $startTime, $endTime, $excludeEventId = null) {
        try {
            $sql = "
                SELECT
                    e.event_id,
                    e.event_title,
                    e.event_date,
                    e.start_time,
                    e.end_time,
                    e.event_type_id,
                    et.event_name as event_type_name,
                    CONCAT(c.user_firstName, ' ', c.user_lastName) as client_name,
                    COALESCE(v.venue_title, 'TBD') as venue_name
                FROM tbl_events e
                LEFT JOIN tbl_users c ON e.user_id = c.user_id
                LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                WHERE e.event_date = ?
                AND e.event_status NOT IN ('cancelled', 'completed')
                AND (
                    (e.start_time < ? AND e.end_time > ?) OR
                    (e.start_time < ? AND e.end_time > ?) OR
                    (e.start_time >= ? AND e.end_time <= ?)
                )
            ";

            $params = [$eventDate, $endTime, $startTime, $endTime, $startTime, $startTime, $endTime];

            if ($excludeEventId) {
                $sql .= " AND e.event_id != ?";
                $params[] = $excludeEventId;
            }

            $sql .= " ORDER BY e.start_time";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format conflicts for frontend
            $formattedConflicts = [];
            $hasWedding = false;
            $hasOtherEvents = false;

            foreach ($conflicts as $conflict) {
                $formattedConflicts[] = [
                    'event_id' => (int)$conflict['event_id'],
                    'event_title' => $conflict['event_title'],
                    'event_date' => $conflict['event_date'],
                    'start_time' => $conflict['start_time'],
                    'end_time' => $conflict['end_time'],
                    'event_type_id' => (int)$conflict['event_type_id'],
                    'event_type_name' => $conflict['event_type_name'],
                    'client_name' => $conflict['client_name'] ?: 'Unknown Client',
                    'venue_name' => $conflict['venue_name'] ?: 'TBD'
                ];

                // Check for wedding conflicts (business rule: only one wedding per day)
                if ($conflict['event_type_id'] == 1) {
                    $hasWedding = true;
                } else {
                    $hasOtherEvents = true;
                }
            }

            $response = [
                'hasConflicts' => count($formattedConflicts) > 0,
                'hasWedding' => $hasWedding,
                'hasOtherEvents' => $hasOtherEvents,
                'conflicts' => $formattedConflicts,
                'totalConflicts' => count($formattedConflicts),
                'checkDate' => $eventDate,
                'checkStartTime' => $startTime,
                'checkEndTime' => $endTime
            ];

            return json_encode([
                "status" => "success",
                "hasConflicts" => $response['hasConflicts'],
                "hasWedding" => $response['hasWedding'],
                "hasOtherEvents" => $response['hasOtherEvents'],
                "conflicts" => $response['conflicts']
            ]);
        } catch (Exception $e) {
            error_log("checkEventConflicts error: " . $e->getMessage());
            return json_encode([
                "status" => "error",
                "message" => "Failed to check event conflicts: " . $e->getMessage(),
                "hasConflicts" => false,
                "hasWedding" => false,
                "hasOtherEvents" => false,
                "conflicts" => []
            ]);
        }
    }

    public function getCalendarConflictData($startDate, $endDate) {
        try {
            $sql = "
                SELECT
                    e.event_date,
                    e.event_type_id,
                    et.event_name as event_type_name,
                    COUNT(*) as event_count
                FROM tbl_events e
                LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                WHERE e.event_date BETWEEN ? AND ?
                AND e.event_status NOT IN ('cancelled', 'completed')
                GROUP BY e.event_date, e.event_type_id, et.event_name
                ORDER BY e.event_date
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$startDate, $endDate]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Structure the data for frontend calendar
            $calendarData = [];

            foreach ($events as $event) {
                $date = $event['event_date'];
                $eventTypeId = (int)$event['event_type_id'];

                if (!isset($calendarData[$date])) {
                    $calendarData[$date] = [
                        'hasWedding' => false,
                        'hasOtherEvents' => false,
                        'eventCount' => 0,
                        'events' => []
                    ];
                }

                $calendarData[$date]['eventCount'] += $event['event_count'];

                if ($eventTypeId == 1) { // Wedding
                    $calendarData[$date]['hasWedding'] = true;
                } else {
                    $calendarData[$date]['hasOtherEvents'] = true;
                }

                $calendarData[$date]['events'][] = [
                    'event_type_id' => $eventTypeId,
                    'event_type_name' => $event['event_type_name'],
                    'count' => $event['event_count']
                ];
            }

            return json_encode([
                "status" => "success",
                "calendarData" => $calendarData,
                "dateRange" => [
                    "startDate" => $startDate,
                    "endDate" => $endDate
                ]
            ]);
        } catch (Exception $e) {
            error_log("getCalendarConflictData error: " . $e->getMessage());
            return json_encode([
                "status" => "error",
                "message" => "Failed to get calendar conflict data: " . $e->getMessage(),
                "calendarData" => []
            ]);
        }
    }
        public function getEventById($eventId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    e.*,
                    CONCAT(c.user_firstName, ' ', c.user_lastName) as client_name,
                    c.user_firstName as client_first_name,
                    c.user_lastName as client_last_name,
                    c.user_suffix as client_suffix,
                    c.user_email as client_email,
                    c.user_contact as client_contact,
                    c.user_pfp as client_pfp,
                    c.user_birthdate as client_birthdate,
                    c.created_at as client_joined_date,
                    c.user_username as client_username,
                    CONCAT(a.user_firstName, ' ', a.user_lastName) as admin_name,
                    CONCAT(org.user_firstName, ' ', org.user_lastName) as organizer_name,
                    et.event_name as event_type_name,
                    et.event_description as event_type_description,
                    p.package_title,
                    p.package_description,
                    v.venue_title,
                    v.venue_location,
                    v.venue_contact,
                    v.venue_capacity,
                    v.venue_price,
                    pst.schedule_name as payment_schedule_name,
                    pst.schedule_description as payment_schedule_description,
                    pst.installment_count
                FROM tbl_events e
                LEFT JOIN tbl_users c ON e.user_id = c.user_id
                LEFT JOIN tbl_users a ON e.admin_id = a.user_id
                LEFT JOIN tbl_users org ON e.organizer_id = org.user_id
                LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                LEFT JOIN tbl_packages p ON e.package_id = p.package_id
                LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                LEFT JOIN tbl_payment_schedule_types pst ON e.payment_schedule_type_id = pst.schedule_type_id
                WHERE e.event_id = ?
            ");
                        $stmt->execute([$eventId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($event) {
                // Parse event_attachments JSON field
                if (!empty($event['event_attachments'])) {
                    $event['attachments'] = json_decode($event['event_attachments'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $event['attachments'] = [];
                        error_log("JSON decode error for event attachments: " . json_last_error_msg());
                    }
                } else {
                    $event['attachments'] = [];
                }

                // For testing: Add sample attachments if event has none (only for event 28)
                if ($eventId == 28 && empty($event['attachments'])) {
                    $event['attachments'] = [
                        [
                            'file_name' => 'wedding_contract_2025.pdf',
                            'original_name' => 'Wedding Contract - Laurenz & Partner.pdf',
                            'file_path' => 'uploads/event_attachments/sample_contract.pdf',
                            'file_size' => 245760,
                            'file_type' => 'application/pdf',
                            'upload_date' => '2025-06-25 10:30:00',
                            'description' => 'Signed wedding contract and terms'
                        ],
                        [
                            'file_name' => 'venue_layout_plan.jpg',
                            'original_name' => 'Pearlmont Hotel Layout Plan.jpg',
                            'file_path' => 'uploads/event_attachments/venue_layout.jpg',
                            'file_size' => 512000,
                            'file_type' => 'image/jpeg',
                            'upload_date' => '2025-06-25 11:15:00',
                            'description' => 'Venue seating arrangement and layout'
                        ],
                        [
                            'file_name' => 'menu_preferences.docx',
                            'original_name' => 'Catering Menu Selections.docx',
                            'file_path' => 'uploads/event_attachments/menu_selections.docx',
                            'file_size' => 87040,
                            'file_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'upload_date' => '2025-06-25 14:45:00',
                            'description' => 'Detailed menu preferences and dietary requirements'
                        ]
                    ];

                    error_log("Added sample attachments for event 28");
                }

                // Debug client profile picture
                error_log("Event ID: $eventId, Client PFP: " . ($event['client_pfp'] ?? 'NULL'));

                // Get event components
                $stmt = $this->pdo->prepare("
                    SELECT * FROM tbl_event_components
                    WHERE event_id = ?
                    ORDER BY display_order
                ");
                $stmt->execute([$eventId]);
                $event['components'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Get event timeline
                $stmt = $this->pdo->prepare("
                    SELECT * FROM tbl_event_timeline
                    WHERE event_id = ?
                    ORDER BY display_order
                ");
                $stmt->execute([$eventId]);
                $event['timeline'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Get payment history (exclude cancelled payments)
                $stmt = $this->pdo->prepare("
                    SELECT * FROM tbl_payments
                    WHERE event_id = ? AND payment_status != 'cancelled'
                    ORDER BY payment_date DESC, created_at DESC
                ");
                $stmt->execute([$eventId]);
                $event['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Get payment proofs/attachments
                $stmt = $this->pdo->prepare("
                    SELECT
                        payment_id,
                        payment_amount,
                        payment_date,
                        payment_method,
                        payment_status,
                        payment_reference,
                        payment_notes as description,
                        created_at
                    FROM tbl_payments
                    WHERE event_id = ? AND payment_reference IS NOT NULL
                    ORDER BY payment_date DESC
                ");
                $stmt->execute([$eventId]);
                $paymentProofs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Add payment proofs to attachments if they exist
                foreach ($paymentProofs as $proof) {
                    if (!empty($proof['payment_reference'])) {
                        $event['attachments'][] = [
                            'file_name' => "Payment Proof - " . $proof['payment_reference'],
                            'file_path' => $proof['payment_reference'],
                            'file_type' => 'payment_proof',
                            'upload_date' => $proof['payment_date'],
                            'file_size' => null,
                            'description' => $proof['description'] ?? 'Payment proof document'
                        ];
                    }
                }

                return json_encode([
                    "status" => "success",
                    "event" => $event
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Event not found"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch event: " . $e->getMessage()
            ]);
        }
    }

    public function uploadEventAttachment($eventId, $file, $description = '') {
        try {
            $uploadDir = 'uploads/event_attachments/';

            // Create directory if it doesn't exist
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $fileName = time() . '_' . $file['name'];
            $filePath = $uploadDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // Get current attachments
                $stmt = $this->pdo->prepare("SELECT event_attachments FROM tbl_events WHERE event_id = ?");
                $stmt->execute([$eventId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                $attachments = [];
                if (!empty($result['event_attachments'])) {
                    $attachments = json_decode($result['event_attachments'], true) ?: [];
                }

                // Add new attachment
                $newAttachment = [
                    'file_name' => $fileName,
                    'original_name' => $file['name'],
                    'file_path' => $filePath,
                    'file_size' => $file['size'],
                    'file_type' => $file['type'],
                    'upload_date' => date('Y-m-d H:i:s'),
                    'description' => $description
                ];

                $attachments[] = $newAttachment;

                // Update event with new attachments
                $stmt = $this->pdo->prepare("UPDATE tbl_events SET event_attachments = ? WHERE event_id = ?");
                $stmt->execute([json_encode($attachments), $eventId]);

                return json_encode([
                    "status" => "success",
                    "message" => "File uploaded successfully",
                    "attachment" => $newAttachment
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to upload file"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Upload error: " . $e->getMessage()
            ]);
        }
    }

    public function getEnhancedEventDetails($eventId) {
        try {
            // Get comprehensive event details using the enhanced view
            $stmt = $this->pdo->prepare("
                SELECT
                    e.*,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                    u.user_firstName as client_first_name,
                    u.user_lastName as client_last_name,
                    u.user_suffix as client_suffix,
                    u.user_email as client_email,
                    u.user_contact as client_contact,
                    u.user_pfp as client_pfp,
                    u.user_birthdate as client_birthdate,
                    u.created_at as client_joined_date,
                    u.user_username as client_username,
                    CONCAT(a.user_firstName, ' ', a.user_lastName) as admin_name,
                    CONCAT(org.user_firstName, ' ', org.user_lastName) as organizer_name,
                    CONCAT(cb.user_firstName, ' ', cb.user_lastName) as created_by_name,
                    CONCAT(ub.user_firstName, ' ', ub.user_lastName) as updated_by_name,
                    et.event_name as event_type_name,
                    et.event_description as event_type_description,
                    p.package_title,
                    p.package_description,
                    v.venue_title,
                    v.venue_location,
                    v.venue_contact,
                    v.venue_capacity,
                    v.venue_price,
                    pst.schedule_name as payment_schedule_name,
                    pst.schedule_description as payment_schedule_description,
                    pst.installment_count,
                    wd.id as wedding_details_id
                FROM tbl_events e
                LEFT JOIN tbl_users u ON e.user_id = u.user_id
                LEFT JOIN tbl_users a ON e.admin_id = a.user_id
                LEFT JOIN tbl_users org ON e.organizer_id = org.user_id
                LEFT JOIN tbl_users cb ON e.created_by = cb.user_id
                LEFT JOIN tbl_users ub ON e.updated_by = ub.user_id
                LEFT JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                LEFT JOIN tbl_packages p ON e.package_id = p.package_id
                LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                LEFT JOIN tbl_payment_schedule_types pst ON e.payment_schedule_type_id = pst.schedule_type_id
                LEFT JOIN tbl_wedding_details wd ON e.event_wedding_form_id = wd.id
                WHERE e.event_id = ?
            ");
            $stmt->execute([$eventId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$event) {
                return json_encode([
                    "status" => "error",
                    "message" => "Event not found"
                ]);
            }

            // Get event components
            $stmt = $this->pdo->prepare("
                SELECT
                    ec.*,
                    pc.component_name as original_component_name,
                    pc.component_description as original_component_description
                FROM tbl_event_components ec
                LEFT JOIN tbl_package_components pc ON ec.original_package_component_id = pc.component_id
                WHERE ec.event_id = ?
                ORDER BY ec.display_order
            ");
            $stmt->execute([$eventId]);
            $event['components'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get event timeline
            $stmt = $this->pdo->prepare("
                SELECT
                    et.*,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as assigned_to_name
                FROM tbl_event_timeline et
                LEFT JOIN tbl_users u ON et.assigned_to = u.user_id
                WHERE et.event_id = ?
                ORDER BY et.display_order
            ");
            $stmt->execute([$eventId]);
            $event['timeline'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get payment schedule
            $stmt = $this->pdo->prepare("
                SELECT * FROM tbl_event_payment_schedules
                WHERE event_id = ?
                ORDER BY installment_number
            ");
            $stmt->execute([$eventId]);
            $event['payment_schedule'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get payments (exclude cancelled payments)
            $stmt = $this->pdo->prepare("
                SELECT
                    p.*,
                    eps.installment_number,
                    eps.due_date as schedule_due_date
                FROM tbl_payments p
                LEFT JOIN tbl_event_payment_schedules eps ON p.schedule_id = eps.schedule_id
                WHERE p.event_id = ? AND p.payment_status != 'cancelled'
                ORDER BY p.payment_date DESC
            ");
            $stmt->execute([$eventId]);
            $event['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get wedding details if this is a wedding event and has wedding form
            if ($event['event_type_id'] == 1 && $event['event_wedding_form_id']) {
                $weddingDetails = $this->getWeddingDetails($eventId);
                $weddingResponse = json_decode($weddingDetails, true);
                if ($weddingResponse['status'] === 'success') {
                    $event['wedding_details'] = $weddingResponse['wedding_details'];
                }
            }

            // Get feedback if available
            if ($event['event_feedback_id']) {
                $stmt = $this->pdo->prepare("
                    SELECT
                        f.*,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as feedback_by_name
                    FROM tbl_feedback f
                    LEFT JOIN tbl_users u ON f.user_id = u.user_id
                    WHERE f.feedback_id = ?
                ");
                $stmt->execute([$event['event_feedback_id']]);
                $event['feedback'] = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // Parse JSON fields if they exist
            if ($event['event_attachments']) {
                $event['event_attachments'] = json_decode($event['event_attachments'], true);
            }
            if ($event['recurrence_rule']) {
                $event['recurrence_rule'] = json_decode($event['recurrence_rule'], true);
            }

            return json_encode([
                "status" => "success",
                "event" => $event
            ]);

        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch event details: " . $e->getMessage()
            ]);
        }
    }

    public function updateBookingStatus($bookingId, $status) {
        try {
            // Validate booking status
            $validStatuses = ['pending', 'confirmed', 'converted', 'cancelled', 'completed'];
            if (!in_array($status, $validStatuses)) {
                return json_encode(["status" => "error", "message" => "Invalid booking status"]);
            }

            // Check if booking exists
            $checkSql = "SELECT booking_id, booking_reference, user_id FROM tbl_bookings WHERE booking_id = :booking_id";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->execute([':booking_id' => $bookingId]);
            $booking = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                return json_encode(["status" => "error", "message" => "Booking not found"]);
            }

            // Update booking status
            $sql = "UPDATE tbl_bookings SET booking_status = :status, updated_at = NOW() WHERE booking_id = :booking_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':status' => $status,
                ':booking_id' => $bookingId
            ]);

            // Create notification for client
            $notificationMessage = '';
            switch ($status) {
                case 'confirmed':
                    $notificationMessage = "Your booking {$booking['booking_reference']} has been accepted! You can now proceed with event planning.";
                    break;
                case 'cancelled':
                    $notificationMessage = "Your booking {$booking['booking_reference']} has been cancelled.";
                    break;
                case 'completed':
                    $notificationMessage = "Your booking {$booking['booking_reference']} has been completed.";
                    break;
                default:
                    $notificationMessage = "Your booking {$booking['booking_reference']} status has been updated to {$status}.";
            }

            // Insert notification
            $notificationSql = "INSERT INTO tbl_notifications (user_id, booking_id, notification_message, notification_status)
                               VALUES (:user_id, :booking_id, :message, 'unread')";
            $notificationStmt = $this->conn->prepare($notificationSql);
            $notificationStmt->execute([
                ':user_id' => $booking['user_id'],
                ':booking_id' => $bookingId,
                ':message' => $notificationMessage
            ]);

            return json_encode([
                "status" => "success",
                "message" => "Booking status updated successfully"
            ]);
        } catch (Exception $e) {
            error_log("updateBookingStatus error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function confirmBooking($bookingReference) {
        try {
            // Check if booking exists
            $checkSql = "SELECT booking_id, user_id, booking_status FROM tbl_bookings WHERE booking_reference = :reference";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->execute([':reference' => $bookingReference]);
            $booking = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                return json_encode(["status" => "error", "message" => "Booking not found"]);
            }

            if ($booking['booking_status'] === 'confirmed') {
                return json_encode(["status" => "error", "message" => "Booking is already confirmed"]);
            }

            // Update booking status to confirmed
            $sql = "UPDATE tbl_bookings SET booking_status = 'confirmed', updated_at = NOW() WHERE booking_reference = :reference";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':reference' => $bookingReference]);

            // Create notification for client
            $notificationSql = "INSERT INTO tbl_notifications (user_id, booking_id, notification_message, notification_status)
                               VALUES (:user_id, :booking_id, :message, 'unread')";
            $notificationStmt = $this->conn->prepare($notificationSql);
            $notificationStmt->execute([
                ':user_id' => $booking['user_id'],
                ':booking_id' => $booking['booking_id'],
                ':message' => "Your booking {$bookingReference} has been confirmed! You can now proceed with event planning."
            ]);

            return json_encode([
                "status" => "success",
                "message" => "Booking confirmed successfully"
            ]);
        } catch (Exception $e) {
            error_log("confirmBooking error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getAllBookings() {
        try {
            $sql = "SELECT
                        b.booking_id,
                        b.booking_reference,
                        b.user_id,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                        u.user_email as client_email,
                        u.user_contact as client_phone,
                        b.event_type_id,
                        et.event_name as event_type_name,
                        b.event_name,
                        b.event_date,
                        b.event_time,
                        b.start_time,
                        b.end_time,
                        b.guest_count,
                        b.venue_id,
                        v.venue_title as venue_name,
                        b.package_id,
                        p.package_title as package_name,
                        b.notes,
                        b.booking_status,
                        b.created_at,
                        b.updated_at,
                        CASE WHEN e.event_id IS NOT NULL THEN 1 ELSE 0 END as is_converted,
                        e.event_id as converted_event_id
                    FROM tbl_bookings b
                    JOIN tbl_users u ON b.user_id = u.user_id
                    JOIN tbl_event_type et ON b.event_type_id = et.event_type_id
                    LEFT JOIN tbl_venue v ON b.venue_id = v.venue_id
                    LEFT JOIN tbl_packages p ON b.package_id = p.package_id
                    LEFT JOIN tbl_events e ON b.booking_reference = e.original_booking_reference
                    ORDER BY b.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "bookings" => $bookings,
                "count" => count($bookings)
            ]);
        } catch (Exception $e) {
            error_log("getAllBookings error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getConfirmedBookings() {
        try {
            $sql = "SELECT
                        b.booking_id,
                        b.booking_reference,
                        b.user_id,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                        u.user_email as client_email,
                        u.user_contact as client_phone,
                        b.event_type_id,
                        et.event_name as event_type_name,
                        b.event_name,
                        b.event_date,
                        b.event_time,
                        b.start_time,
                        b.end_time,
                        b.guest_count,
                        b.venue_id,
                        v.venue_title as venue_name,
                        b.package_id,
                        p.package_title as package_name,
                        b.notes,
                        b.booking_status,
                        b.created_at,
                        b.updated_at,
                        CASE WHEN e.event_id IS NOT NULL THEN 1 ELSE 0 END as is_converted,
                        e.event_id as converted_event_id
                    FROM tbl_bookings b
                    JOIN tbl_users u ON b.user_id = u.user_id
                    JOIN tbl_event_type et ON b.event_type_id = et.event_type_id
                    LEFT JOIN tbl_venue v ON b.venue_id = v.venue_id
                    LEFT JOIN tbl_packages p ON b.package_id = p.package_id
                    LEFT JOIN tbl_events e ON b.booking_reference = e.original_booking_reference
                    WHERE b.booking_status = 'confirmed'
                    ORDER BY b.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "bookings" => $bookings,
                "count" => count($bookings)
            ]);
        } catch (Exception $e) {
            error_log("getConfirmedBookings error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function searchBookings($search) {
        try {
            $sql = "SELECT
                        b.booking_id,
                        b.booking_reference,
                        b.user_id,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                        u.user_email as client_email,
                        u.user_contact as client_phone,
                        b.event_type_id,
                        et.event_name as event_type_name,
                        b.event_name,
                        b.event_date,
                        b.event_time,
                        b.start_time,
                        b.end_time,
                        b.guest_count,
                        b.venue_id,
                        v.venue_title as venue_name,
                        b.package_id,
                        p.package_title as package_name,
                        b.notes,
                        b.booking_status,
                        b.created_at,
                        b.updated_at,
                        CASE WHEN e.event_id IS NOT NULL THEN 1 ELSE 0 END as is_converted,
                        e.event_id as converted_event_id
                    FROM tbl_bookings b
                    JOIN tbl_users u ON b.user_id = u.user_id
                    JOIN tbl_event_type et ON b.event_type_id = et.event_type_id
                    LEFT JOIN tbl_venue v ON b.venue_id = v.venue_id
                    LEFT JOIN tbl_packages p ON b.package_id = p.package_id
                    LEFT JOIN tbl_events e ON b.booking_reference = e.original_booking_reference
                    WHERE b.booking_status = 'confirmed' AND (
                        b.booking_reference LIKE :search1 OR
                        CONCAT(u.user_firstName, ' ', u.user_lastName) LIKE :search2 OR
                        b.event_name LIKE :search3
                    )
                    ORDER BY b.created_at DESC";
            $stmt = $this->conn->prepare($sql);
            $searchTerm = "%$search%";
            $stmt->execute([
                ':search1' => $searchTerm,
                ':search2' => $searchTerm,
                ':search3' => $searchTerm
            ]);
            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return json_encode([
                "status" => "success",
                "bookings" => $bookings,
                "count" => count($bookings)
            ]);
        } catch (Exception $e) {
            error_log("searchBookings error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getEventByBookingReference($bookingReference) { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function testBookingsTable() { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function createVenue() {
        try {
            // Validate required fields
            $requiredFields = ['venue_title', 'venue_location', 'venue_contact', 'venue_capacity', 'venue_price'];
            foreach ($requiredFields as $field) {
                if (!isset($_POST[$field]) || empty($_POST[$field])) {
                    return json_encode([
                        "status" => "error",
                        "message" => "Missing required field: " . $field
                    ]);
                }
            }

            // Start transaction
            $this->conn->beginTransaction();

            // Handle file uploads first
            $profilePicture = null;
            $coverPhoto = null;

            if (isset($_FILES['venue_profile_picture']) && $_FILES['venue_profile_picture']['error'] === 0) {
                $uploadResult = json_decode($this->uploadVenueFile($_FILES['venue_profile_picture'], 'venue_profile_pictures'), true);
                if ($uploadResult['status'] === 'success') {
                    $profilePicture = $uploadResult['filePath'];
                } else {
                    throw new Exception("Failed to upload profile picture: " . $uploadResult['message']);
                }
            }

            if (isset($_FILES['venue_cover_photo']) && $_FILES['venue_cover_photo']['error'] === 0) {
                $uploadResult = json_decode($this->uploadVenueFile($_FILES['venue_cover_photo'], 'venue_cover_photos'), true);
                if ($uploadResult['status'] === 'success') {
                    $coverPhoto = $uploadResult['filePath'];
                } else {
                    throw new Exception("Failed to upload cover photo: " . $uploadResult['message']);
                }
            }

            // Set default user_id and venue_owner (can be customized later)
            $userId = isset($_POST['user_id']) ? $_POST['user_id'] : 7; // Default admin user
            $venueOwner = isset($_POST['venue_owner']) ? $_POST['venue_owner'] : 'Admin';

            // Validate venue_type against enum values
            $validVenueTypes = ['indoor', 'outdoor', 'hybrid', 'garden', 'hall', 'pavilion'];
            $venueType = isset($_POST['venue_type']) ? $_POST['venue_type'] : 'indoor';

            // Map frontend venue_type to database enum values
            if ($venueType === 'internal') {
                $venueType = 'indoor';
            } elseif ($venueType === 'external') {
                $venueType = 'outdoor';
            }

            if (!in_array($venueType, $validVenueTypes)) {
                $venueType = 'indoor'; // Default fallback
            }

            // Insert venue with all required fields
            $sql = "INSERT INTO tbl_venue (
                venue_title, venue_details, venue_location, venue_contact,
                venue_type, venue_capacity, venue_price, extra_pax_rate, is_active,
                venue_profile_picture, venue_cover_photo, user_id, venue_owner,
                venue_status
            ) VALUES (
                :venue_title, :venue_details, :venue_location, :venue_contact,
                :venue_type, :venue_capacity, :venue_price, :extra_pax_rate, :is_active,
                :venue_profile_picture, :venue_cover_photo, :user_id, :venue_owner,
                :venue_status
            )";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                'venue_title' => $_POST['venue_title'],
                'venue_details' => $_POST['venue_details'] ?? '',
                'venue_location' => $_POST['venue_location'],
                'venue_contact' => $_POST['venue_contact'],
                'venue_type' => $venueType,
                'venue_capacity' => $_POST['venue_capacity'],
                'venue_price' => $_POST['venue_price'],
                'extra_pax_rate' => isset($_POST['extra_pax_rate']) ? $_POST['extra_pax_rate'] : 0.00,
                'is_active' => isset($_POST['is_active']) ? $_POST['is_active'] : 1,
                'venue_profile_picture' => $profilePicture,
                'venue_cover_photo' => $coverPhoto,
                'user_id' => $userId,
                'venue_owner' => $venueOwner,
                'venue_status' => 'available'
            ]);

            $venueId = $this->conn->lastInsertId();

            // Handle inclusions if provided
            if (isset($_POST['inclusions_data'])) {
                $inclusions = json_decode($_POST['inclusions_data'], true);

                if (is_array($inclusions)) {
                    foreach ($inclusions as $inclusion) {
                        // Insert inclusion
                        $sql = "INSERT INTO tbl_venue_inclusions (
                            venue_id, inclusion_name, inclusion_description, inclusion_price
                        ) VALUES (
                            :venue_id, :inclusion_name, :inclusion_description, :inclusion_price
                        )";

                        $stmt = $this->conn->prepare($sql);
                        $stmt->execute([
                            'venue_id' => $venueId,
                            'inclusion_name' => $inclusion['inclusion_name'],
                            'inclusion_description' => $inclusion['inclusion_description'] ?? '',
                            'inclusion_price' => $inclusion['inclusion_price'] ?? 0
                        ]);

                        $inclusionId = $this->conn->lastInsertId();

                        // Handle components if provided
                        if (isset($inclusion['components']) && is_array($inclusion['components'])) {
                            foreach ($inclusion['components'] as $component) {
                                $sql = "INSERT INTO tbl_venue_components (
                                    inclusion_id, component_name, component_description
                                ) VALUES (
                                    :inclusion_id, :component_name, :component_description
                                )";

                                $stmt = $this->conn->prepare($sql);
                                $stmt->execute([
                                    'inclusion_id' => $inclusionId,
                                    'component_name' => $component['component_name'],
                                    'component_description' => $component['component_description'] ?? ''
                                ]);
                            }
                        }
                    }
                }
            }

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Venue created successfully",
                "venue_id" => $venueId
            ]);

        } catch (Exception $e) {
            $this->conn->rollBack();
            return json_encode([
                "status" => "error",
                "message" => "Failed to create venue: " . $e->getMessage()
            ]);
        }
    }
    public function checkAndFixVenuePaxRates() {
        try {
            // First, let's see what venues we have
            $sql = "SELECT venue_id, venue_title, venue_price, extra_pax_rate FROM tbl_venue WHERE is_active = 1";
            $stmt = $this->conn->query($sql);
            $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $results = [];
            $updates = [];

            foreach ($venues as $venue) {
                $venueTitle = $venue['venue_title'];
                $currentPaxRate = floatval($venue['extra_pax_rate']);

                // Define expected pax rates based on venue names
                $expectedPaxRate = 0;
                if (stripos($venueTitle, 'Pearlmont Hotel') !== false && stripos($venueTitle, 'Package 2') !== false) {
                    $expectedPaxRate = 300.00;
                } elseif (stripos($venueTitle, 'Pearlmont Hotel') !== false) {
                    $expectedPaxRate = 350.00;
                } elseif (stripos($venueTitle, 'Demiren') !== false) {
                    $expectedPaxRate = 200.00;
                }

                $results[] = [
                    'venue_id' => $venue['venue_id'],
                    'venue_title' => $venueTitle,
                    'current_pax_rate' => $currentPaxRate,
                    'expected_pax_rate' => $expectedPaxRate,
                    'needs_update' => $expectedPaxRate > 0 && $currentPaxRate != $expectedPaxRate
                ];

                // Update if needed
                if ($expectedPaxRate > 0 && $currentPaxRate != $expectedPaxRate) {
                    $updateSql = "UPDATE tbl_venue SET extra_pax_rate = ? WHERE venue_id = ?";
                    $updateStmt = $this->conn->prepare($updateSql);
                    $updateStmt->execute([$expectedPaxRate, $venue['venue_id']]);

                    $updates[] = [
                        'venue_id' => $venue['venue_id'],
                        'venue_title' => $venueTitle,
                        'old_rate' => $currentPaxRate,
                        'new_rate' => $expectedPaxRate
                    ];
                }
            }

            return json_encode([
                "status" => "success",
                "message" => "Venue pax rates checked and updated",
                "venues" => $results,
                "updates" => $updates,
                "total_venues" => count($venues),
                "updated_count" => count($updates)
            ]);
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    public function setVenuePaxRate($venueId, $paxRate) {
        try {
            $sql = "UPDATE tbl_venue SET extra_pax_rate = ? WHERE venue_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$paxRate, $venueId]);

            // Get the updated venue data
            $getSql = "SELECT venue_id, venue_title, venue_price, extra_pax_rate FROM tbl_venue WHERE venue_id = ?";
            $getStmt = $this->conn->prepare($getSql);
            $getStmt->execute([$venueId]);
            $venue = $getStmt->fetch(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "message" => "Venue pax rate updated successfully",
                "venue" => $venue
            ]);
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    public function testVenueData() {
        try {
            $sql = "SELECT venue_id, venue_title, venue_price, extra_pax_rate FROM tbl_venue WHERE is_active = 1 LIMIT 5";
            $stmt = $this->conn->query($sql);
            $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "message" => "Venue data test",
                "venues" => $venues,
                "total_venues" => count($venues),
                "venues_with_pax_rates" => array_filter($venues, function($venue) {
                    return floatval($venue['extra_pax_rate']) > 0;
                })
            ]);
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    public function getAllVenues() {
        try {
            $sql = "SELECT v.*,
                    GROUP_CONCAT(DISTINCT vc.component_name) as components,
                    GROUP_CONCAT(DISTINCT vi.inclusion_name) as inclusions
                    FROM tbl_venue v
                    LEFT JOIN tbl_venue_inclusions vi ON v.venue_id = vi.venue_id
                    LEFT JOIN tbl_venue_components vc ON vi.inclusion_id = vc.inclusion_id
                    GROUP BY v.venue_id
                    ORDER BY v.created_at DESC";

            $stmt = $this->conn->query($sql);
            $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($venues as &$venue) {
                $venue['components'] = $venue['components'] ? explode(',', $venue['components']) : [];
                $venue['inclusions'] = $venue['inclusions'] ? explode(',', $venue['inclusions']) : [];
                $venue['is_active'] = (bool)$venue['is_active'];

                // Add pax rate information
                $venue['extra_pax_rate'] = floatval($venue['extra_pax_rate'] ?? 0);
                $venue['has_pax_rate'] = $venue['extra_pax_rate'] > 0;
                $venue['base_capacity'] = 100; // Base capacity for pax rate calculation
            }

            return json_encode([
                "status" => "success",
                "data" => $venues
            ]);
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }
    public function getVenueById($venueId) {
        try {
            // Get venue basic info
            $sql = "SELECT * FROM tbl_venue WHERE venue_id = :venue_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':venue_id' => $venueId]);
            $venue = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$venue) {
                return json_encode(["status" => "error", "message" => "Venue not found"]);
            }

            // Get venue inclusions
            $inclusionsSql = "SELECT * FROM tbl_venue_inclusions WHERE venue_id = :venue_id AND is_active = 1 ORDER BY inclusion_name";
            $inclusionsStmt = $this->conn->prepare($inclusionsSql);
            $inclusionsStmt->execute([':venue_id' => $venueId]);
            $inclusions = $inclusionsStmt->fetchAll(PDO::FETCH_ASSOC);

            // For each inclusion, get its components
            foreach ($inclusions as &$inclusion) {
                $componentsSql = "SELECT * FROM tbl_venue_components WHERE inclusion_id = :inclusion_id AND is_active = 1 ORDER BY component_name";
                $componentsStmt = $this->conn->prepare($componentsSql);
                $componentsStmt->execute([':inclusion_id' => $inclusion['inclusion_id']]);
                $inclusion['components'] = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $venue['inclusions'] = $inclusions;

            // Add pax rate information
            $venue['extra_pax_rate'] = floatval($venue['extra_pax_rate'] ?? 0);
            $venue['has_pax_rate'] = $venue['extra_pax_rate'] > 0;
            $venue['base_capacity'] = 100; // Base capacity for pax rate calculation

            return json_encode([
                "status" => "success",
                "venue" => $venue
            ]);
        } catch (Exception $e) {
            error_log("getVenueById error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function updateVenue($data) {
        try {
            $this->conn->beginTransaction();

            // Update venue basic information
            $stmt = $this->conn->prepare("
                UPDATE tbl_venue SET
                    venue_title = ?,
                    venue_owner = ?,
                    venue_location = ?,
                    venue_contact = ?,
                    venue_details = ?,
                    venue_status = ?,
                    venue_capacity = ?,
                    venue_price = ?,
                    extra_pax_rate = ?,
                    venue_type = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE venue_id = ?
            ");

            $stmt->execute([
                $data['venue_title'],
                $data['venue_owner'],
                $data['venue_location'],
                $data['venue_contact'],
                $data['venue_details'] ?? '',
                $data['venue_status'] ?? 'available',
                $data['venue_capacity'],
                $data['venue_price'],
                $data['extra_pax_rate'] ?? 0.00,
                $data['venue_type'] ?? 'indoor',
                $data['venue_id']
            ]);

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Venue updated successfully"
            ]);
        } catch (Exception $e) {
            $this->conn->rollback();
            return json_encode([
                "status" => "error",
                "message" => "Failed to update venue: " . $e->getMessage()
            ]);
        }
    }
    public function getVenuesForPackage() {
        try {
            $sql = "SELECT v.*,
                    GROUP_CONCAT(DISTINCT vc.component_name) as components,
                    GROUP_CONCAT(DISTINCT vi.inclusion_name) as inclusions,
                    COALESCE(v.venue_price, 0) as total_price,
                    COALESCE(v.extra_pax_rate, 0) as extra_pax_rate
                    FROM tbl_venue v
                    LEFT JOIN tbl_venue_inclusions vi ON v.venue_id = vi.venue_id
                    LEFT JOIN tbl_venue_components vc ON vi.inclusion_id = vc.inclusion_id
                    WHERE v.is_active = 1
                    GROUP BY v.venue_id
                    ORDER BY v.created_at DESC";

            $stmt = $this->conn->query($sql);
            $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($venues as &$venue) {
                $venue['components'] = $venue['components'] ? explode(',', $venue['components']) : [];
                $venue['inclusions'] = $venue['inclusions'] ? explode(',', $venue['inclusions']) : [];
                $venue['total_price'] = floatval($venue['total_price']);
                $venue['extra_pax_rate'] = floatval($venue['extra_pax_rate']);

                // Add pax rate information
                $venue['has_pax_rate'] = $venue['extra_pax_rate'] > 0;
                $venue['base_capacity'] = 100; // Base capacity for pax rate calculation
            }

            return json_encode([
                "status" => "success",
                "venues" => $venues
            ]);
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    // Get all available venues for "start from scratch" events
    public function getAllAvailableVenues() {
        try {
            $sql = "SELECT v.*,
                           COALESCE(v.venue_price, 0) as venue_price,
                           COALESCE(v.extra_pax_rate, 0) as extra_pax_rate,
                           v.venue_capacity,
                           v.venue_type,
                           v.venue_profile_picture,
                           v.venue_cover_photo
                    FROM tbl_venue v
                    WHERE v.is_active = 1 AND v.venue_status = 'available'
                    ORDER BY v.venue_title ASC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "venues" => $venues
            ]);
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    public function calculateVenuePricing($venueId, $guestCount) {
        try {
            // Get venue details
            $sql = "SELECT venue_id, venue_title, venue_price, extra_pax_rate, venue_capacity
                    FROM tbl_venue
                    WHERE venue_id = :venue_id AND is_active = 1";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':venue_id' => $venueId]);
            $venue = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$venue) {
                return json_encode([
                    "status" => "error",
                    "message" => "Venue not found"
                ]);
            }

            $basePrice = floatval($venue['venue_price']);
            $extraPaxRate = floatval($venue['extra_pax_rate']);
            $baseCapacity = 100; // Base capacity for pax rate calculation

            // Calculate overflow charges
            $extraGuests = max(0, $guestCount - $baseCapacity);
            $overflowCharge = $extraGuests * $extraPaxRate;
            $totalPrice = $basePrice + $overflowCharge;

            return json_encode([
                "status" => "success",
                "venue_id" => $venueId,
                "venue_title" => $venue['venue_title'],
                "guest_count" => $guestCount,
                "base_price" => $basePrice,
                "extra_pax_rate" => $extraPaxRate,
                "base_capacity" => $baseCapacity,
                "extra_guests" => $extraGuests,
                "overflow_charge" => $overflowCharge,
                "total_price" => $totalPrice,
                "has_overflow" => $extraGuests > 0
            ]);

        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    public function createPackageWithVenues($data) {
        try {
            $this->conn->beginTransaction();

            // Validate required fields
            $packageData = $data['package_data'];
            if (empty($packageData['package_title']) || empty($packageData['package_price']) ||
                empty($packageData['guest_capacity']) || empty($packageData['created_by'])) {
                return json_encode(["status" => "error", "message" => "Package title, price, guest capacity, and creator are required"]);
            }

            // Insert main package
            $sql = "INSERT INTO tbl_packages (package_title, package_description, package_price, guest_capacity, created_by, is_active)
                    VALUES (:title, :description, :price, :capacity, :created_by, 1)";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':title' => $packageData['package_title'],
                ':description' => $packageData['package_description'] ?? '',
                ':price' => $packageData['package_price'],
                ':capacity' => $packageData['guest_capacity'],
                ':created_by' => $packageData['created_by']
            ]);

            $packageId = $this->conn->lastInsertId();

            // Set original price and lock the package price after creation
            $lockPriceSql = "UPDATE tbl_packages SET
                                original_price = package_price,
                                is_price_locked = 1,
                                price_lock_date = CURRENT_TIMESTAMP
                            WHERE package_id = :package_id";
            $lockStmt = $this->conn->prepare($lockPriceSql);
            $lockStmt->execute([':package_id' => $packageId]);

            // Insert components if provided
            if (!empty($data['components']) && is_array($data['components'])) {
                foreach ($data['components'] as $index => $component) {
                    if (!empty($component['component_name'])) {
                        $componentSql = "INSERT INTO tbl_package_components (package_id, component_name, component_description, component_price, display_order)
                                        VALUES (:package_id, :name, :description, :price, :order)";
                        $componentStmt = $this->conn->prepare($componentSql);
                        $componentStmt->execute([
                            ':package_id' => $packageId,
                            ':name' => $component['component_name'],
                            ':description' => $component['component_description'] ?? '',
                            ':price' => $component['component_price'] ?? 0,
                            ':order' => $index
                        ]);
                    }
                }
            }

            // Insert freebies if provided
            if (!empty($data['freebies']) && is_array($data['freebies'])) {
                foreach ($data['freebies'] as $index => $freebie) {
                    if (!empty($freebie['freebie_name'])) {
                        $freebieSql = "INSERT INTO tbl_package_freebies (package_id, freebie_name, freebie_description, freebie_value, display_order)
                                      VALUES (:package_id, :name, :description, :value, :order)";
                        $freebieStmt = $this->conn->prepare($freebieSql);
                        $freebieStmt->execute([
                            ':package_id' => $packageId,
                            ':name' => $freebie['freebie_name'],
                            ':description' => $freebie['freebie_description'] ?? '',
                            ':value' => $freebie['freebie_value'] ?? 0,
                            ':order' => $index
                        ]);
                    }
                }
            }

            // Insert event types if provided
            if (!empty($data['event_types']) && is_array($data['event_types'])) {
                foreach ($data['event_types'] as $eventTypeId) {
                    $eventTypeSql = "INSERT INTO tbl_package_event_types (package_id, event_type_id) VALUES (:package_id, :event_type_id)";
                    $eventTypeStmt = $this->conn->prepare($eventTypeSql);
                    $eventTypeStmt->execute([
                        ':package_id' => $packageId,
                        ':event_type_id' => $eventTypeId
                    ]);
                }
            }

            // Insert venues if provided
            if (!empty($data['venue_ids']) && is_array($data['venue_ids'])) {
                foreach ($data['venue_ids'] as $venueId) {
                    $venueSql = "INSERT INTO tbl_package_venues (package_id, venue_id) VALUES (:package_id, :venue_id)";
                    $venueStmt = $this->conn->prepare($venueSql);
                    $venueStmt->execute([
                        ':package_id' => $packageId,
                        ':venue_id' => $venueId
                    ]);
                }
            }

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Package created successfully",
                "package_id" => $packageId
            ]);
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("createPackageWithVenues error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getDashboardMetrics($adminId) {
        try {
            $metrics = [];

            // Get total events for this admin
            $totalEventsSql = "SELECT COUNT(*) as total FROM tbl_events WHERE admin_id = ?";
            $stmt = $this->conn->prepare($totalEventsSql);
            $stmt->execute([$adminId]);
            $metrics['totalEvents'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get total revenue (sum of all completed payments for admin's events)
            $revenueSql = "SELECT COALESCE(SUM(p.payment_amount), 0) as total_revenue
                          FROM tbl_payments p
                          JOIN tbl_events e ON p.event_id = e.event_id
                          WHERE e.admin_id = ? AND p.payment_status = 'completed'";
            $stmt = $this->conn->prepare($revenueSql);
            $stmt->execute([$adminId]);
            $metrics['totalRevenue'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_revenue'];

            // Get total clients (unique clients who have events with this admin)
            $clientsSql = "SELECT COUNT(DISTINCT e.user_id) as total
                          FROM tbl_events e
                          WHERE e.admin_id = ?";
            $stmt = $this->conn->prepare($clientsSql);
            $stmt->execute([$adminId]);
            $metrics['totalClients'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get completed events
            $completedSql = "SELECT COUNT(*) as total
                            FROM tbl_events
                            WHERE admin_id = ? AND event_status = 'completed'";
            $stmt = $this->conn->prepare($completedSql);
            $stmt->execute([$adminId]);
            $metrics['completedEvents'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Calculate monthly growth (comparing this month vs last month)
            $currentMonth = date('Y-m');
            $lastMonth = date('Y-m', strtotime('-1 month'));

            // Events growth
            $currentMonthEventsSql = "SELECT COUNT(*) as total FROM tbl_events
                                     WHERE admin_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ?";
            $stmt = $this->conn->prepare($currentMonthEventsSql);
            $stmt->execute([$adminId, $currentMonth]);
            $currentMonthEvents = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $stmt->execute([$adminId, $lastMonth]);
            $lastMonthEvents = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $eventsGrowth = $lastMonthEvents > 0 ? (($currentMonthEvents - $lastMonthEvents) / $lastMonthEvents) * 100 : 0;

            // Revenue growth
            $currentMonthRevenueSql = "SELECT COALESCE(SUM(p.payment_amount), 0) as total
                                      FROM tbl_payments p
                                      JOIN tbl_events e ON p.event_id = e.event_id
                                      WHERE e.admin_id = ? AND p.payment_status = 'completed'
                                      AND DATE_FORMAT(p.created_at, '%Y-%m') = ?";
            $stmt = $this->conn->prepare($currentMonthRevenueSql);
            $stmt->execute([$adminId, $currentMonth]);
            $currentMonthRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $stmt->execute([$adminId, $lastMonth]);
            $lastMonthRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $revenueGrowth = $lastMonthRevenue > 0 ? (($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100 : 0;

            // Clients growth
            $currentMonthClientsSql = "SELECT COUNT(DISTINCT e.user_id) as total
                                      FROM tbl_events e
                                      WHERE e.admin_id = ? AND DATE_FORMAT(e.created_at, '%Y-%m') = ?";
            $stmt = $this->conn->prepare($currentMonthClientsSql);
            $stmt->execute([$adminId, $currentMonth]);
            $currentMonthClients = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $stmt->execute([$adminId, $lastMonth]);
            $lastMonthClients = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $clientsGrowth = $lastMonthClients > 0 ? (($currentMonthClients - $lastMonthClients) / $lastMonthClients) * 100 : 0;

            // Completed events growth
            $currentMonthCompletedSql = "SELECT COUNT(*) as total
                                        FROM tbl_events
                                        WHERE admin_id = ? AND event_status = 'completed'
                                        AND DATE_FORMAT(updated_at, '%Y-%m') = ?";
            $stmt = $this->conn->prepare($currentMonthCompletedSql);
            $stmt->execute([$adminId, $currentMonth]);
            $currentMonthCompleted = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $stmt->execute([$adminId, $lastMonth]);
            $lastMonthCompleted = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $completedGrowth = $lastMonthCompleted > 0 ? (($currentMonthCompleted - $lastMonthCompleted) / $lastMonthCompleted) * 100 : 0;

            $metrics['monthlyGrowth'] = [
                'events' => round($eventsGrowth, 1),
                'revenue' => round($revenueGrowth, 1),
                'clients' => round($clientsGrowth, 1),
                'completed' => round($completedGrowth, 1)
            ];

            return json_encode([
                "status" => "success",
                "metrics" => $metrics
            ]);
        } catch (Exception $e) {
            error_log("getDashboardMetrics error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getUpcomingEvents($adminId, $limit = 5) {
        try {
            $sql = "SELECT
                        e.event_id as id,
                        e.event_title as title,
                        e.event_date as date,
                        e.event_status as status,
                        e.total_budget as budget,
                        COALESCE(v.venue_title, 'No venue selected') as venue,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as client
                    FROM tbl_events e
                    LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                    LEFT JOIN tbl_users u ON e.user_id = u.user_id
                    WHERE e.admin_id = ?
                    AND e.event_date >= CURDATE()
                    AND e.event_status IN ('draft', 'confirmed', 'in-progress')
                    ORDER BY e.event_date ASC
                    LIMIT ?";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$adminId, $limit]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "events" => $events
            ]);
        } catch (Exception $e) {
            error_log("getUpcomingEvents error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function getRecentPayments($adminId, $limit = 5) {
        try {
            $sql = "SELECT
                        p.payment_id as id,
                        e.event_title as event,
                        p.payment_date as date,
                        p.payment_amount as amount,
                        p.payment_method as type,
                        p.payment_status as status
                    FROM tbl_payments p
                    JOIN tbl_events e ON p.event_id = e.event_id
                    WHERE e.admin_id = ?
                    ORDER BY p.created_at DESC
                    LIMIT ?";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$adminId, $limit]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "payments" => $payments
            ]);
        } catch (Exception $e) {
            error_log("getRecentPayments error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
    public function createPayment($data) {
        try {
            $this->pdo->beginTransaction();

            // Check for duplicate payment reference if provided
            if (!empty($data['payment_reference'])) {
                $referenceCheckSql = "SELECT payment_id FROM tbl_payments WHERE payment_reference = ? LIMIT 1";
                $referenceCheckStmt = $this->pdo->prepare($referenceCheckSql);
                $referenceCheckStmt->execute([$data['payment_reference']]);
                if ($referenceCheckStmt->fetch(PDO::FETCH_ASSOC)) {
                    $this->pdo->rollback();
                    return json_encode([
                        "status" => "error",
                        "message" => "Payment reference already exists. Please use a unique reference number."
                    ]);
                }
            }

            // Handle payment attachments if provided
            $attachments = null;
            if (isset($data['payment_attachments']) && !empty($data['payment_attachments'])) {
                $attachments = json_encode($data['payment_attachments']);
            }

            // Insert payment record
            $stmt = $this->pdo->prepare("
                INSERT INTO tbl_payments (
                    event_id, schedule_id, client_id, payment_method,
                    payment_amount, payment_notes, payment_status,
                    payment_date, payment_reference, payment_attachments
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['event_id'],
                $data['schedule_id'] ?? null,
                $data['client_id'],
                $data['payment_method'],
                $data['payment_amount'],
                $data['payment_notes'] ?? '',
                $data['payment_status'] ?? 'completed',
                $data['payment_date'] ?? date('Y-m-d'),
                $data['payment_reference'] ?? '',
                $attachments
            ]);

            $paymentId = $this->pdo->lastInsertId();

            $this->pdo->commit();

            return json_encode([
                "status" => "success",
                "payment_id" => $paymentId,
                "message" => "Payment created successfully"
            ]);
        } catch (Exception $e) {
            $this->pdo->rollback();
            return json_encode([
                "status" => "error",
                "message" => "Failed to create payment: " . $e->getMessage()
            ]);
        }
    }
    public function getEventPayments($eventId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    p.*,
                    u.user_firstName,
                    u.user_lastName,
                    eps.installment_number,
                    eps.due_date,
                    eps.amount_due as schedule_amount_due
                FROM tbl_payments p
                LEFT JOIN tbl_users u ON p.client_id = u.user_id
                LEFT JOIN tbl_event_payment_schedules eps ON p.schedule_id = eps.schedule_id
                WHERE p.event_id = ?
                ORDER BY p.payment_date DESC
            ");
            $stmt->execute([$eventId]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "payments" => $payments
            ]);
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch event payments: " . $e->getMessage()
            ]);
        }
    }
    public function getClientPayments($clientId) { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function getAdminPayments($adminId) {
        try {
            $query = "
                SELECT
                    p.payment_id,
                    p.payment_amount,
                    p.payment_method,
                    p.payment_status,
                    p.payment_date,
                    p.payment_reference,
                    p.payment_notes,
                    e.event_title,
                    e.event_date,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name
                FROM tbl_payments p
                INNER JOIN tbl_events e ON p.event_id = e.event_id
                INNER JOIN tbl_users u ON p.client_id = u.user_id
                WHERE e.admin_id = :admin_id
                ORDER BY p.payment_date DESC, p.created_at DESC
            ";

            $stmt = $this->pdo->prepare($query);
            $stmt->bindParam(':admin_id', $adminId, PDO::PARAM_INT);
            $stmt->execute();

            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "payments" => $payments
            ]);

        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch admin payments: " . $e->getMessage()
            ]);
        }
    }
    public function updatePaymentStatus($paymentId, $status, $notes = null) {
        try {
            $this->pdo->beginTransaction();

            // Get payment details first
            $query = "SELECT * FROM tbl_payments WHERE payment_id = :payment_id";
            $stmt = $this->pdo->prepare($query);
            $stmt->bindParam(':payment_id', $paymentId, PDO::PARAM_INT);
            $stmt->execute();
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                throw new Exception("Payment not found");
            }

            // Update payment status
            $updateQuery = "UPDATE tbl_payments SET payment_status = :status, updated_at = CURRENT_TIMESTAMP WHERE payment_id = :payment_id";
            $updateStmt = $this->pdo->prepare($updateQuery);
            $updateStmt->bindParam(':status', $status);
            $updateStmt->bindParam(':payment_id', $paymentId, PDO::PARAM_INT);
            $updateStmt->execute();

            // Log the status change
            $logQuery = "
                INSERT INTO tbl_payment_logs
                (event_id, payment_id, client_id, action_type, amount, reference_number, notes)
                VALUES (:event_id, :payment_id, :client_id, 'payment_confirmed', :amount, :reference_number, :notes)
            ";
            $logStmt = $this->pdo->prepare($logQuery);
            $logStmt->bindParam(':event_id', $payment['event_id'], PDO::PARAM_INT);
            $logStmt->bindParam(':payment_id', $paymentId, PDO::PARAM_INT);
            $logStmt->bindParam(':client_id', $payment['client_id'], PDO::PARAM_INT);
            $logStmt->bindParam(':amount', $payment['payment_amount']);
            $logStmt->bindParam(':reference_number', $payment['payment_reference']);
            $logStmt->bindParam(':notes', $notes);
            $logStmt->execute();

            $this->pdo->commit();

            return json_encode([
                "status" => "success",
                "message" => "Payment status updated successfully"
            ]);

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return json_encode([
                "status" => "error",
                "message" => "Failed to update payment status: " . $e->getMessage()
            ]);
        }
    }
    public function getPaymentSchedule($eventId) { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function getEventsWithPaymentStatus($adminId) {
        try {
            $query = "
                SELECT
                    e.event_id,
                    e.event_title,
                    e.event_date,
                    e.total_budget,
                    e.payment_status as event_payment_status,
                    COALESCE(SUM(p.payment_amount), 0) as total_paid,
                    (e.total_budget - COALESCE(SUM(p.payment_amount), 0)) as remaining_balance,
                    CASE
                        WHEN e.total_budget > 0 THEN ROUND((COALESCE(SUM(p.payment_amount), 0) / e.total_budget) * 100, 2)
                        ELSE 0
                    END as payment_percentage,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                    COUNT(p.payment_id) as payment_count
                FROM tbl_events e
                LEFT JOIN tbl_users u ON e.user_id = u.user_id
                LEFT JOIN tbl_payments p ON e.event_id = p.event_id AND p.payment_status = 'completed'
                WHERE e.admin_id = :admin_id
                GROUP BY e.event_id
                ORDER BY e.event_date DESC
            ";

            $stmt = $this->pdo->prepare($query);
            $stmt->bindParam(':admin_id', $adminId, PDO::PARAM_INT);
            $stmt->execute();

            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "events" => $events
            ]);

        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch events with payment status: " . $e->getMessage()
            ]);
        }
    }
    public function getPaymentAnalytics($adminId, $startDate = null, $endDate = null) {
        try {
            // Base date filter
            $dateFilter = "";
            if ($startDate && $endDate) {
                $dateFilter = "AND p.payment_date BETWEEN :start_date AND :end_date";
            }

            $query = "
                SELECT
                    COUNT(DISTINCT e.event_id) as total_events,
                    COUNT(p.payment_id) as total_payments,
                    COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) as total_revenue,
                    COALESCE(SUM(CASE WHEN p.payment_status = 'pending' THEN p.payment_amount ELSE 0 END), 0) as pending_payments,
                    CASE
                        WHEN COUNT(CASE WHEN p.payment_status = 'completed' THEN 1 END) > 0
                        THEN COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) / COUNT(CASE WHEN p.payment_status = 'completed' THEN 1 END)
                        ELSE 0
                    END as average_payment,
                    COUNT(CASE WHEN p.payment_method = 'gcash' AND p.payment_status = 'completed' THEN 1 END) as gcash_payments,
                    COUNT(CASE WHEN p.payment_method = 'bank-transfer' AND p.payment_status = 'completed' THEN 1 END) as bank_payments,
                    COUNT(CASE WHEN p.payment_method = 'cash' AND p.payment_status = 'completed' THEN 1 END) as cash_payments
                FROM tbl_events e
                LEFT JOIN tbl_payments p ON e.event_id = p.event_id
                WHERE e.admin_id = :admin_id $dateFilter
            ";

            $stmt = $this->pdo->prepare($query);
            $stmt->bindParam(':admin_id', $adminId, PDO::PARAM_INT);

            if ($startDate && $endDate) {
                $stmt->bindParam(':start_date', $startDate);
                $stmt->bindParam(':end_date', $endDate);
            }

            $stmt->execute();
            $analytics = $stmt->fetch(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "analytics" => $analytics
            ]);

        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch payment analytics: " . $e->getMessage()
            ]);
        }
    }
    public function createPaymentSchedule($data) { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function getEventPaymentSchedule($eventId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    eps.*,
                    pst.schedule_name,
                    e.event_title,
                    e.total_budget
                FROM tbl_event_payment_schedules eps
                LEFT JOIN tbl_payment_schedule_types pst ON eps.schedule_type_id = pst.schedule_type_id
                LEFT JOIN tbl_events e ON eps.event_id = e.event_id
                WHERE eps.event_id = ?
                ORDER BY eps.installment_number
            ");
            $stmt->execute([$eventId]);
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "schedules" => $schedules
            ]);
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch event payment schedule: " . $e->getMessage()
            ]);
        }
    }
    public function getPaymentScheduleTypes() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM tbl_payment_schedule_types
                WHERE is_active = 1
                ORDER BY schedule_type_id
            ");
            $stmt->execute();
            $scheduleTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "scheduleTypes" => $scheduleTypes
            ]);
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch payment schedule types: " . $e->getMessage()
            ]);
        }
    }
    public function recordScheduledPayment($data) { return json_encode(["status" => "error", "message" => "Method not implemented"]); }
    public function getPaymentLogs($eventId) {
        try {
            $query = "
                SELECT
                    pl.log_id,
                    pl.event_id,
                    pl.payment_id,
                    pl.action_type,
                    pl.amount,
                    pl.reference_number,
                    pl.notes,
                    pl.created_at,
                    CONCAT(c.user_firstName, ' ', c.user_lastName) as client_name,
                    CONCAT(a.user_firstName, ' ', a.user_lastName) as admin_name,
                    p.payment_method,
                    p.payment_status,
                    e.event_title
                FROM tbl_payment_logs pl
                LEFT JOIN tbl_users c ON pl.client_id = c.user_id
                LEFT JOIN tbl_users a ON pl.admin_id = a.user_id
                LEFT JOIN tbl_payments p ON pl.payment_id = p.payment_id
                LEFT JOIN tbl_events e ON pl.event_id = e.event_id
                WHERE pl.event_id = :event_id
                ORDER BY pl.created_at DESC
            ";

            $stmt = $this->pdo->prepare($query);
            $stmt->bindParam(':event_id', $eventId, PDO::PARAM_INT);
            $stmt->execute();

            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "logs" => $logs
            ]);

        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch payment logs: " . $e->getMessage()
            ]);
        }
    }
    public function getAdminPaymentLogs($adminId, $limit = 50) {
        try {
            $query = "
                SELECT
                    pl.log_id,
                    pl.event_id,
                    pl.payment_id,
                    pl.action_type,
                    pl.amount,
                    pl.reference_number,
                    pl.notes,
                    pl.created_at,
                    CONCAT(c.user_firstName, ' ', c.user_lastName) as client_name,
                    CONCAT(a.user_firstName, ' ', a.user_lastName) as admin_name,
                    p.payment_method,
                    p.payment_status,
                    e.event_title
                FROM tbl_payment_logs pl
                LEFT JOIN tbl_users c ON pl.client_id = c.user_id
                LEFT JOIN tbl_users a ON pl.admin_id = a.user_id
                LEFT JOIN tbl_payments p ON pl.payment_id = p.payment_id
                LEFT JOIN tbl_events e ON pl.event_id = e.event_id
                WHERE e.admin_id = :admin_id
                ORDER BY pl.created_at DESC
                LIMIT :limit
            ";

            $stmt = $this->pdo->prepare($query);
            $stmt->bindParam(':admin_id', $adminId, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "logs" => $logs
            ]);

        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch admin payment logs: " . $e->getMessage()
            ]);
        }
    }

    public function getEnhancedPaymentDashboard($adminId) { return json_encode(["status" => "error", "message" => "Method not implemented"]); }

    // Settings Methods
    public function getUserProfile($userId) {
        try {
            $sql = "SELECT user_id, user_firstName, user_lastName, user_email, user_contact, user_pfp, created_at
                    FROM tbl_users WHERE user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$userId]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($profile) {
                return json_encode([
                    "status" => "success",
                    "profile" => $profile
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "User not found"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error fetching profile: " . $e->getMessage()
            ]);
        }
    }

    public function updateUserProfile($data) {
        try {
            // Build dynamic update query to handle optional profile picture
            $updateFields = [
                "user_firstName = ?",
                "user_lastName = ?",
                "user_email = ?",
                "user_contact = ?"
            ];

            $params = [
                $data['firstName'],
                $data['lastName'],
                $data['email'],
                $data['contact']
            ];

            // Add profile picture if provided
            if (isset($data['user_pfp'])) {
                $updateFields[] = "user_pfp = ?";
                $params[] = $data['user_pfp'];
            }

            $params[] = $data['user_id'];

            $sql = "UPDATE tbl_users SET " . implode(", ", $updateFields) . " WHERE user_id = ?";

            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute($params);

            if ($result) {
                return json_encode([
                    "status" => "success",
                    "message" => "Profile updated successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to update profile"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error updating profile: " . $e->getMessage()
            ]);
        }
    }

    public function changePassword($data) {
        try {
            // Verify current password
            $sql = "SELECT user_password FROM tbl_users WHERE user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$data['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($data['currentPassword'], $user['user_password'])) {
                return json_encode([
                    "status" => "error",
                    "message" => "Current password is incorrect"
                ]);
            }

            // Update password
            $hashedPassword = password_hash($data['newPassword'], PASSWORD_DEFAULT);
            $updateSql = "UPDATE tbl_users SET user_password = ? WHERE user_id = ?";
            $updateStmt = $this->conn->prepare($updateSql);
            $result = $updateStmt->execute([$hashedPassword, $data['user_id']]);

            if ($result) {
                return json_encode([
                    "status" => "success",
                    "message" => "Password changed successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to change password"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error changing password: " . $e->getMessage()
            ]);
        }
    }

    public function getWebsiteSettings() {
        try {
            // Check if settings table exists, if not create default settings
            $checkSql = "SELECT COUNT(*) as count FROM information_schema.tables
                        WHERE table_schema = DATABASE() AND table_name = 'tbl_website_settings'";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->execute();
            $tableExists = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

            if (!$tableExists) {
                // Create table if it doesn't exist
                $createTableSql = "CREATE TABLE tbl_website_settings (
                    setting_id INT PRIMARY KEY AUTO_INCREMENT,
                    company_name VARCHAR(255) DEFAULT 'Event Coordination System',
                    company_logo TEXT,
                    hero_image TEXT,
                    primary_color VARCHAR(7) DEFAULT '#16a34a',
                    secondary_color VARCHAR(7) DEFAULT '#059669',
                    contact_email VARCHAR(255),
                    contact_phone VARCHAR(50),
                    address TEXT,
                    about_text TEXT,
                    social_facebook VARCHAR(255),
                    social_instagram VARCHAR(255),
                    social_twitter VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )";
                $this->conn->exec($createTableSql);

                // Insert default settings
                $insertSql = "INSERT INTO tbl_website_settings (company_name) VALUES ('Event Coordination System')";
                $this->conn->exec($insertSql);
            }

            $sql = "SELECT * FROM tbl_website_settings ORDER BY setting_id DESC LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($settings) {
                return json_encode([
                    "status" => "success",
                    "settings" => $settings
                ]);
            } else {
                return json_encode([
                    "status" => "success",
                    "settings" => [
                        "company_name" => "Event Coordination System",
                        "primary_color" => "#16a34a",
                        "secondary_color" => "#059669"
                    ]
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error fetching website settings: " . $e->getMessage()
            ]);
        }
    }

    public function updateWebsiteSettings($settings) {
        try {
            $sql = "UPDATE tbl_website_settings SET
                        company_name = ?,
                        company_logo = ?,
                        hero_image = ?,
                        primary_color = ?,
                        secondary_color = ?,
                        contact_email = ?,
                        contact_phone = ?,
                        address = ?,
                        about_text = ?,
                        social_facebook = ?,
                        social_instagram = ?,
                        social_twitter = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE setting_id = (SELECT MAX(setting_id) FROM (SELECT setting_id FROM tbl_website_settings) as temp)";

            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                $settings['company_name'],
                $settings['company_logo'],
                $settings['hero_image'],
                $settings['primary_color'],
                $settings['secondary_color'],
                $settings['contact_email'],
                $settings['contact_phone'],
                $settings['address'],
                $settings['about_text'],
                $settings['social_facebook'],
                $settings['social_instagram'],
                $settings['social_twitter']
            ]);

            if ($result) {
                return json_encode([
                    "status" => "success",
                    "message" => "Website settings updated successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to update website settings"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error updating website settings: " . $e->getMessage()
            ]);
        }
    }

    public function getAllFeedbacks() {
        try {
            $sql = "SELECT
                        f.*,
                        CONCAT(u.user_firstName, ' ', u.user_lastName) as user_name,
                        u.user_firstName,
                        u.user_lastName,
                        u.user_email,
                        v.venue_title,
                        s.store_name
                    FROM tbl_feedback f
                    LEFT JOIN tbl_users u ON f.user_id = u.user_id
                    LEFT JOIN tbl_venue v ON f.venue_id = v.venue_id
                    LEFT JOIN tbl_store s ON f.store_id = s.store_id
                    ORDER BY f.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "feedbacks" => $feedbacks
            ]);
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error fetching feedbacks: " . $e->getMessage()
            ]);
        }
    }

    public function deleteFeedback($feedbackId) {
        try {
            $sql = "DELETE FROM tbl_feedback WHERE feedback_id = ?";
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([$feedbackId]);

            if ($result) {
                return json_encode([
                    "status" => "success",
                    "message" => "Feedback deleted successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to delete feedback"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error deleting feedback: " . $e->getMessage()
            ]);
        }
    }

    public function uploadFile($file, $fileType) {
        try {
            $uploadDir = "uploads/";

            // Create directory based on file type
            switch ($fileType) {
                case 'profile':
                case 'profile_picture':
                    $uploadDir .= "profile_pictures/";
                    break;
                case 'company_logo':
                    $uploadDir .= "website/logos/";
                    break;
                case 'hero_image':
                    $uploadDir .= "website/hero/";
                    break;
                case 'event_attachment':
                    $uploadDir .= "event_attachments/";
                    break;
                case 'payment_proof':
                    $uploadDir .= "payment_proofs/";
                    break;
                case 'venue_profile_pictures':
                    $uploadDir .= "venue_profile_pictures/";
                    break;
                case 'venue_cover_photos':
                    $uploadDir .= "venue_cover_photos/";
                    break;
                case 'resume':
                    $uploadDir .= "resumes/";
                    break;
                case 'certification':
                    $uploadDir .= "certifications/";
                    break;
                default:
                    $uploadDir .= "misc/";
            }

            // Create directory if it doesn't exist
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // Generate unique filename
            $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = time() . '_' . uniqid() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;

            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                return json_encode([
                    "status" => "success",
                    "filePath" => $filePath,
                    "message" => "File uploaded successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to upload file"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error uploading file: " . $e->getMessage()
            ]);
        }
    }

    public function uploadProfilePicture($file, $userId) {
        try {
            $uploadDir = "uploads/profile_pictures/";

            // Create directory if it doesn't exist
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = $file['type'] ?? mime_content_type($file['tmp_name']);

            if (!in_array($fileType, $allowedTypes)) {
                return json_encode([
                    "status" => "error",
                    "message" => "Invalid file type. Only images are allowed."
                ]);
            }

            // Generate unique filename
            $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
            if (empty($fileExtension)) {
                $fileExtension = 'jpg'; // Default for blob uploads
            }
            $fileName = 'profile_' . $userId . '_' . time() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;

            // Delete old profile picture if exists
            $getUserSql = "SELECT user_pfp FROM tbl_users WHERE user_id = ?";
            $getUserStmt = $this->conn->prepare($getUserSql);
            $getUserStmt->execute([$userId]);
            $userData = $getUserStmt->fetch(PDO::FETCH_ASSOC);

            if ($userData && $userData['user_pfp'] && file_exists($userData['user_pfp'])) {
                unlink($userData['user_pfp']);
            }

            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // Update user profile picture in database
                $updateSql = "UPDATE tbl_users SET user_pfp = ? WHERE user_id = ?";
                $updateStmt = $this->conn->prepare($updateSql);
                $updateStmt->execute([$filePath, $userId]);

                return json_encode([
                    "status" => "success",
                    "filePath" => $filePath,
                    "message" => "Profile picture uploaded successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to upload file"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error uploading profile picture: " . $e->getMessage()
            ]);
        }
    }

    public function uploadVenueFile($file, $fileType) {
        try {
            // Validate file type for venues (images only)
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file['type'], $allowedTypes)) {
                return json_encode([
                    "status" => "error",
                    "message" => "Invalid file type. Only images are allowed for venue uploads."
                ]);
            }

            // Validate file size (max 5MB for venue images)
            if ($file['size'] > 5 * 1024 * 1024) {
                return json_encode([
                    "status" => "error",
                    "message" => "File too large. Maximum 5MB allowed for venue images."
                ]);
            }

            $uploadDir = "uploads/" . $fileType . "/";

            // Create directory if it doesn't exist
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // Generate unique filename with timestamp
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $fileName = time() . '_' . uniqid() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;

            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                return json_encode([
                    "status" => "success",
                    "filePath" => $filePath,
                    "fileName" => $fileName,
                    "message" => "Venue file uploaded successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to upload venue file"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Error uploading venue file: " . $e->getMessage()
            ]);
        }
    }

    public function uploadPaymentProof($eventId, $file, $description, $proofType) {
        try {
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                return json_encode(["status" => "error", "message" => "File upload error"]);
            }

            // Validate file size (max 10MB)
            if ($file['size'] > 10 * 1024 * 1024) {
                return json_encode(["status" => "error", "message" => "File too large. Maximum 10MB allowed."]);
            }

            // Validate file type (expanded to support more document types)
            $allowedTypes = [
                // Images
                'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
                // Documents
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                // Text files
                'text/plain', 'text/csv',
                // Other common formats
                'application/zip', 'application/x-zip-compressed'
            ];
            if (!in_array($file['type'], $allowedTypes)) {
                return json_encode(["status" => "error", "message" => "Invalid file type. Allowed: Images, PDF, Word, Excel, PowerPoint, Text, and ZIP files."]);
            }

            // Create payment proofs directory
            $uploadDir = "uploads/payment_proofs/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = "payment_proof_{$eventId}_" . time() . '_' . uniqid() . '.' . $extension;
            $targetPath = $uploadDir . $filename;

            // Move the uploaded file
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                // Create new attachment object
                $newAttachment = [
                    'file_name' => $filename,
                    'original_name' => $file['name'],
                    'file_path' => $targetPath,
                    'file_size' => $file['size'],
                    'file_type' => $file['type'],
                    'description' => $description,
                    'proof_type' => $proofType, // receipt, screenshot, bank_slip, other
                    'uploaded_at' => date('Y-m-d H:i:s'),
                ];

                return json_encode([
                    "status" => "success",
                    "message" => "Payment proof uploaded successfully",
                    "attachment" => $newAttachment
                ]);
            } else {
                return json_encode(["status" => "error", "message" => "Failed to move uploaded file"]);
            }

        } catch (Exception $e) {
            error_log("uploadPaymentProof error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Upload error: " . $e->getMessage()]);
        }
    }

    public function getPaymentProofs($eventId) {
        try {
            // Get payment proofs from individual payments
            $sql = "SELECT
                        p.payment_id,
                        p.payment_amount,
                        p.payment_method,
                        p.payment_date,
                        p.payment_reference,
                        p.payment_attachments,
                        p.payment_status
                    FROM tbl_payments p
                    WHERE p.event_id = ? AND p.payment_attachments IS NOT NULL
                    ORDER BY p.payment_date DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$eventId]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $allProofs = [];
            foreach ($payments as $payment) {
                if ($payment['payment_attachments']) {
                    $attachments = json_decode($payment['payment_attachments'], true) ?: [];
                    foreach ($attachments as $attachment) {
                        $attachment['payment_id'] = $payment['payment_id'];
                        $attachment['payment_amount'] = $payment['payment_amount'];
                        $attachment['payment_method'] = $payment['payment_method'];
                        $attachment['payment_date'] = $payment['payment_date'];
                        $attachment['payment_reference'] = $payment['payment_reference'];
                        $attachment['payment_status'] = $payment['payment_status'];
                        $allProofs[] = $attachment;
                    }
                }
            }

            return json_encode([
                "status" => "success",
                "payment_proofs" => $allProofs
            ]);

        } catch (Exception $e) {
            error_log("getPaymentProofs error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Error retrieving payment proofs: " . $e->getMessage()]);
        }
    }

    public function deletePaymentProof($paymentId, $fileName) {
        try {
            // Get current payment attachments
            $sql = "SELECT payment_attachments FROM tbl_payments WHERE payment_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment || !$payment['payment_attachments']) {
                return json_encode(["status" => "error", "message" => "No attachments found"]);
            }

            $attachments = json_decode($payment['payment_attachments'], true) ?: [];

            // Find and remove the payment proof
            $fileFound = false;
            $filePath = "";
            $attachments = array_filter($attachments, function($attachment) use ($fileName, &$fileFound, &$filePath) {
                if ($attachment['file_name'] === $fileName) {
                    $fileFound = true;
                    $filePath = $attachment['file_path'];
                    return false; // Remove this attachment
                }
                return true; // Keep this attachment
            });

            if (!$fileFound) {
                return json_encode(["status" => "error", "message" => "Payment proof not found"]);
            }

            // Delete physical file
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Update payment with remaining attachments
            $updateSql = "UPDATE tbl_payments SET payment_attachments = ? WHERE payment_id = ?";
            $updateStmt = $this->conn->prepare($updateSql);
            $updateStmt->execute([json_encode(array_values($attachments)), $paymentId]);

            return json_encode([
                "status" => "success",
                "message" => "Payment proof deleted successfully"
            ]);

        } catch (Exception $e) {
            error_log("deletePaymentProof error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Error deleting payment proof: " . $e->getMessage()]);
        }
    }

    // Wedding Details Methods
    public function saveWeddingDetails($data) {
        try {
            $this->conn->beginTransaction();

            // Map form field names to database column names
            $mappedData = [
                'event_id' => $data['event_id'],
                'nuptial' => $data['nuptial'] ?? null,
                'motif' => $data['motif'] ?? null,

                // Bride & Groom (map form field names to DB column names)
                'bride_name' => $data['bride_name'] ?? null,
                'bride_size' => $data['bride_gown_size'] ?? $data['bride_size'] ?? null, // Map bride_gown_size to bride_size
                'groom_name' => $data['groom_name'] ?? null,
                'groom_size' => $data['groom_attire_size'] ?? $data['groom_size'] ?? null, // Map groom_attire_size to groom_size

                // Parents (map form field names to DB column names)
                'mother_bride_name' => $data['mothers_attire_name'] ?? $data['mother_bride_name'] ?? null,
                'mother_bride_size' => $data['mothers_attire_size'] ?? $data['mother_bride_size'] ?? null,
                'father_bride_name' => $data['fathers_attire_name'] ?? $data['father_bride_name'] ?? null,
                'father_bride_size' => $data['fathers_attire_size'] ?? $data['father_bride_size'] ?? null,
                'mother_groom_name' => $data['mother_groom_name'] ?? null,
                'mother_groom_size' => $data['mother_groom_size'] ?? null,
                'father_groom_name' => $data['father_groom_name'] ?? null,
                'father_groom_size' => $data['father_groom_size'] ?? null,

                // Principal Sponsors
                'maid_of_honor_name' => $data['maid_of_honor_name'] ?? null,
                'maid_of_honor_size' => $data['maid_of_honor_size'] ?? null,
                'best_man_name' => $data['best_man_name'] ?? null,
                'best_man_size' => $data['best_man_size'] ?? null,

                // Little Bride & Groom
                'little_bride_name' => $data['little_bride_name'] ?? null,
                'little_bride_size' => $data['little_bride_size'] ?? null,
                'little_groom_name' => $data['little_groom_name'] ?? null,
                'little_groom_size' => $data['little_groom_size'] ?? null,

                // Processing Info
                'prepared_by' => $data['prepared_by'] ?? null,
                'received_by' => $data['received_by'] ?? null,
                'pickup_date' => $data['pick_up_date'] ?? $data['pickup_date'] ?? null, // Map pick_up_date to pickup_date
                'return_date' => $data['return_date'] ?? null,
                'customer_signature' => $data['customer_signature'] ?? null
            ];

            // Process wedding party arrays and convert to quantities + JSON
            $weddingParties = ['bridesmaids', 'groomsmen', 'junior_groomsmen', 'flower_girls', 'bearers'];
            foreach ($weddingParties as $party) {
                if (isset($data[$party]) && is_array($data[$party])) {
                    // Extract quantities
                    $mappedData[$party . '_qty'] = count($data[$party]);

                    // Extract names as JSON
                    $names = array_map(function($member) {
                        return $member['name'] ?? '';
                    }, $data[$party]);
                    $mappedData[$party . '_names'] = json_encode($names);
                } else {
                    $mappedData[$party . '_qty'] = 0;
                    $mappedData[$party . '_names'] = json_encode([]);
                }
            }

            // Handle bearer-specific fields (since form uses 'bearers' but DB has specific types)
            $mappedData['ring_bearer_qty'] = 0;
            $mappedData['bible_bearer_qty'] = 0;
            $mappedData['coin_bearer_qty'] = 0;
            $mappedData['ring_bearer_names'] = json_encode([]);
            $mappedData['bible_bearer_names'] = json_encode([]);
            $mappedData['coin_bearer_names'] = json_encode([]);

            // Process wedding items quantities (map form field names to DB column names)
            $itemMappings = [
                'cushions_quantity' => 'cushions_qty',
                'headdress_for_bride_quantity' => 'headdress_qty',
                'shawls_quantity' => 'shawls_qty',
                'veil_cord_quantity' => 'veil_cord_qty',
                'basket_quantity' => 'basket_qty',
                'petticoat_quantity' => 'petticoat_qty',
                'neck_bowtie_quantity' => 'neck_bowtie_qty',
                'garter_leg_quantity' => 'garter_leg_qty',
                'fitting_form_quantity' => 'fitting_form_qty',
                'robe_quantity' => 'robe_qty'
            ];

            foreach ($itemMappings as $formField => $dbField) {
                $mappedData[$dbField] = $data[$formField] ?? $data[$dbField] ?? 0;
            }

            // Check if wedding details already exist for this event
            $checkSql = "SELECT id FROM tbl_wedding_details WHERE event_id = ?";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->execute([$mappedData['event_id']]);
            $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingRecord) {
                // Update existing record
                $sql = "UPDATE tbl_wedding_details SET
                            nuptial = ?, motif = ?, bride_name = ?, bride_size = ?,
                            groom_name = ?, groom_size = ?, mother_bride_name = ?, mother_bride_size = ?,
                            father_bride_name = ?, father_bride_size = ?, mother_groom_name = ?, mother_groom_size = ?,
                            father_groom_name = ?, father_groom_size = ?, maid_of_honor_name = ?, maid_of_honor_size = ?,
                            best_man_name = ?, best_man_size = ?, little_bride_name = ?, little_bride_size = ?,
                            little_groom_name = ?, little_groom_size = ?,
                            bridesmaids_qty = ?, groomsmen_qty = ?, junior_groomsmen_qty = ?, flower_girls_qty = ?,
                            ring_bearer_qty = ?, bible_bearer_qty = ?, coin_bearer_qty = ?,
                            bridesmaids_names = ?, groomsmen_names = ?, junior_groomsmen_names = ?, flower_girls_names = ?,
                            ring_bearer_names = ?, bible_bearer_names = ?, coin_bearer_names = ?,
                            cushions_qty = ?, headdress_qty = ?, shawls_qty = ?, veil_cord_qty = ?,
                            basket_qty = ?, petticoat_qty = ?, neck_bowtie_qty = ?,
                            garter_leg_qty = ?, fitting_form_qty = ?, robe_qty = ?,
                            prepared_by = ?, received_by = ?, pickup_date = ?, return_date = ?,
                            customer_signature = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE event_id = ?";

                $stmt = $this->conn->prepare($sql);
                $stmt->execute([
                    $mappedData['nuptial'], $mappedData['motif'],
                    $mappedData['bride_name'], $mappedData['bride_size'],
                    $mappedData['groom_name'], $mappedData['groom_size'],
                    $mappedData['mother_bride_name'], $mappedData['mother_bride_size'],
                    $mappedData['father_bride_name'], $mappedData['father_bride_size'],
                    $mappedData['mother_groom_name'], $mappedData['mother_groom_size'],
                    $mappedData['father_groom_name'], $mappedData['father_groom_size'],
                    $mappedData['maid_of_honor_name'], $mappedData['maid_of_honor_size'],
                    $mappedData['best_man_name'], $mappedData['best_man_size'],
                    $mappedData['little_bride_name'], $mappedData['little_bride_size'],
                    $mappedData['little_groom_name'], $mappedData['little_groom_size'],
                    $mappedData['bridesmaids_qty'], $mappedData['groomsmen_qty'],
                    $mappedData['junior_groomsmen_qty'], $mappedData['flower_girls_qty'],
                    $mappedData['ring_bearer_qty'], $mappedData['bible_bearer_qty'], $mappedData['coin_bearer_qty'],
                    $mappedData['bridesmaids_names'], $mappedData['groomsmen_names'],
                    $mappedData['junior_groomsmen_names'], $mappedData['flower_girls_names'],
                    $mappedData['ring_bearer_names'], $mappedData['bible_bearer_names'], $mappedData['coin_bearer_names'],
                    $mappedData['cushions_qty'], $mappedData['headdress_qty'], $mappedData['shawls_qty'],
                    $mappedData['veil_cord_qty'], $mappedData['basket_qty'], $mappedData['petticoat_qty'],
                    $mappedData['neck_bowtie_qty'], $mappedData['garter_leg_qty'],
                    $mappedData['fitting_form_qty'], $mappedData['robe_qty'],
                    $mappedData['prepared_by'], $mappedData['received_by'],
                    $mappedData['pickup_date'], $mappedData['return_date'],
                    $mappedData['customer_signature'], $mappedData['event_id']
                ]);
            } else {
                // Insert new record
                $sql = "INSERT INTO tbl_wedding_details (
                            event_id, nuptial, motif, bride_name, bride_size,
                            groom_name, groom_size, mother_bride_name, mother_bride_size,
                            father_bride_name, father_bride_size, mother_groom_name, mother_groom_size,
                            father_groom_name, father_groom_size, maid_of_honor_name, maid_of_honor_size,
                            best_man_name, best_man_size, little_bride_name, little_bride_size,
                            little_groom_name, little_groom_size,
                            bridesmaids_qty, groomsmen_qty, junior_groomsmen_qty, flower_girls_qty,
                            ring_bearer_qty, bible_bearer_qty, coin_bearer_qty,
                            bridesmaids_names, groomsmen_names, junior_groomsmen_names, flower_girls_names,
                            ring_bearer_names, bible_bearer_names, coin_bearer_names,
                            cushions_qty, headdress_qty, shawls_qty, veil_cord_qty,
                            basket_qty, petticoat_qty, neck_bowtie_qty,
                            garter_leg_qty, fitting_form_qty, robe_qty,
                            prepared_by, received_by, pickup_date, return_date, customer_signature
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                        )";

                $stmt = $this->conn->prepare($sql);
                $stmt->execute([
                    $mappedData['event_id'], $mappedData['nuptial'], $mappedData['motif'],
                    $mappedData['bride_name'], $mappedData['bride_size'],
                    $mappedData['groom_name'], $mappedData['groom_size'],
                    $mappedData['mother_bride_name'], $mappedData['mother_bride_size'],
                    $mappedData['father_bride_name'], $mappedData['father_bride_size'],
                    $mappedData['mother_groom_name'], $mappedData['mother_groom_size'],
                    $mappedData['father_groom_name'], $mappedData['father_groom_size'],
                    $mappedData['maid_of_honor_name'], $mappedData['maid_of_honor_size'],
                    $mappedData['best_man_name'], $mappedData['best_man_size'],
                    $mappedData['little_bride_name'], $mappedData['little_bride_size'],
                    $mappedData['little_groom_name'], $mappedData['little_groom_size'],
                    $mappedData['bridesmaids_qty'], $mappedData['groomsmen_qty'],
                    $mappedData['junior_groomsmen_qty'], $mappedData['flower_girls_qty'],
                    $mappedData['ring_bearer_qty'], $mappedData['bible_bearer_qty'], $mappedData['coin_bearer_qty'],
                    $mappedData['bridesmaids_names'], $mappedData['groomsmen_names'],
                    $mappedData['junior_groomsmen_names'], $mappedData['flower_girls_names'],
                    $mappedData['ring_bearer_names'], $mappedData['bible_bearer_names'], $mappedData['coin_bearer_names'],
                    $mappedData['cushions_qty'], $mappedData['headdress_qty'], $mappedData['shawls_qty'],
                    $mappedData['veil_cord_qty'], $mappedData['basket_qty'], $mappedData['petticoat_qty'],
                    $mappedData['neck_bowtie_qty'], $mappedData['garter_leg_qty'],
                    $mappedData['fitting_form_qty'], $mappedData['robe_qty'],
                    $mappedData['prepared_by'], $mappedData['received_by'],
                    $mappedData['pickup_date'], $mappedData['return_date'],
                    $mappedData['customer_signature']
                ]);
            }

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Wedding details saved successfully",
                "debug" => [
                    "mapped_data" => $mappedData,
                    "original_data" => $data
                ]
            ]);
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("saveWeddingDetails error: " . $e->getMessage());
            error_log("Wedding data received: " . json_encode($data));
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getWeddingDetails($eventId) {
        try {
            $sql = "SELECT * FROM tbl_wedding_details WHERE event_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$eventId]);
            $weddingDetails = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($weddingDetails) {
                // Map database column names back to form field names
                $formData = [
                    'nuptial' => $weddingDetails['nuptial'],
                    'motif' => $weddingDetails['motif'],

                    // Map DB field names back to form field names
                    'bride_name' => $weddingDetails['bride_name'],
                    'bride_gown_size' => $weddingDetails['bride_size'], // Map bride_size back to bride_gown_size
                    'groom_name' => $weddingDetails['groom_name'],
                    'groom_attire_size' => $weddingDetails['groom_size'], // Map groom_size back to groom_attire_size

                    // Parents
                    'mothers_attire_name' => $weddingDetails['mother_bride_name'],
                    'mothers_attire_size' => $weddingDetails['mother_bride_size'],
                    'fathers_attire_name' => $weddingDetails['father_bride_name'],
                    'fathers_attire_size' => $weddingDetails['father_bride_size'],
                    'mother_groom_name' => $weddingDetails['mother_groom_name'],
                    'mother_groom_size' => $weddingDetails['mother_groom_size'],
                    'father_groom_name' => $weddingDetails['father_groom_name'],
                    'father_groom_size' => $weddingDetails['father_groom_size'],

                    // Principal Sponsors
                    'maid_of_honor_name' => $weddingDetails['maid_of_honor_name'],
                    'maid_of_honor_size' => $weddingDetails['maid_of_honor_size'],
                    'best_man_name' => $weddingDetails['best_man_name'],
                    'best_man_size' => $weddingDetails['best_man_size'],

                    // Little Bride & Groom
                    'little_bride_name' => $weddingDetails['little_bride_name'],
                    'little_bride_size' => $weddingDetails['little_bride_size'],
                    'little_groom_name' => $weddingDetails['little_groom_name'],
                    'little_groom_size' => $weddingDetails['little_groom_size'],

                    // Processing Info
                    'prepared_by' => $weddingDetails['prepared_by'],
                    'received_by' => $weddingDetails['received_by'],
                    'pick_up_date' => $weddingDetails['pickup_date'], // Map pickup_date back to pick_up_date
                    'return_date' => $weddingDetails['return_date'],
                    'customer_signature' => $weddingDetails['customer_signature'],

                    // Wedding Items (map DB field names back to form field names)
                    'cushions_quantity' => $weddingDetails['cushions_qty'] ?? 0,
                    'headdress_for_bride_quantity' => $weddingDetails['headdress_qty'] ?? 0,
                    'shawls_quantity' => $weddingDetails['shawls_qty'] ?? 0,
                    'veil_cord_quantity' => $weddingDetails['veil_cord_qty'] ?? 0,
                    'basket_quantity' => $weddingDetails['basket_qty'] ?? 0,
                    'petticoat_quantity' => $weddingDetails['petticoat_qty'] ?? 0,
                    'neck_bowtie_quantity' => $weddingDetails['neck_bowtie_qty'] ?? 0,
                    'garter_leg_quantity' => $weddingDetails['garter_leg_qty'] ?? 0,
                    'fitting_form_quantity' => $weddingDetails['fitting_form_qty'] ?? 0,
                    'robe_quantity' => $weddingDetails['robe_qty'] ?? 0
                ];

                // Convert wedding party data back to arrays
                $weddingParties = ['bridesmaids', 'groomsmen', 'junior_groomsmen', 'flower_girls'];
                foreach ($weddingParties as $party) {
                    $names = json_decode($weddingDetails[$party . '_names'] ?? '[]', true);
                    $formData[$party] = [];

                    foreach ($names as $name) {
                        $formData[$party][] = [
                            'name' => $name,
                            'size' => '' // Size information is not stored separately for party members
                        ];
                    }
                }

                // Handle bearers separately
                $bearerNames = json_decode($weddingDetails['ring_bearer_names'] ?? '[]', true);
                $formData['bearers'] = [];
                foreach ($bearerNames as $name) {
                    $formData['bearers'][] = [
                        'name' => $name,
                        'size' => ''
                    ];
                }

                return json_encode([
                    "status" => "success",
                    "wedding_details" => $formData
                ]);
            } else {
                return json_encode([
                    "status" => "success",
                    "wedding_details" => null
                ]);
            }
        } catch (Exception $e) {
            error_log("getWeddingDetails error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    // Migration Methods
    public function runWeddingMigration() {
        try {
            $this->conn->beginTransaction();

            // Drop existing table if it exists to avoid conflicts
            $dropSql = "DROP TABLE IF EXISTS `tbl_wedding_details`";
            $this->conn->exec($dropSql);

            // Create the enhanced wedding details table
            $createSql = "CREATE TABLE `tbl_wedding_details` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `event_id` int(11) NOT NULL,

                -- Basic Information
                `nuptial` varchar(255) DEFAULT NULL,
                `motif` varchar(255) DEFAULT NULL,

                -- Bride & Groom Details
                `bride_name` varchar(255) DEFAULT NULL,
                `bride_size` varchar(50) DEFAULT NULL,
                `groom_name` varchar(255) DEFAULT NULL,
                `groom_size` varchar(50) DEFAULT NULL,

                -- Parents Details
                `mother_bride_name` varchar(255) DEFAULT NULL,
                `mother_bride_size` varchar(50) DEFAULT NULL,
                `father_bride_name` varchar(255) DEFAULT NULL,
                `father_bride_size` varchar(50) DEFAULT NULL,
                `mother_groom_name` varchar(255) DEFAULT NULL,
                `mother_groom_size` varchar(50) DEFAULT NULL,
                `father_groom_name` varchar(255) DEFAULT NULL,
                `father_groom_size` varchar(50) DEFAULT NULL,

                -- Principal Sponsors
                `maid_of_honor_name` varchar(255) DEFAULT NULL,
                `maid_of_honor_size` varchar(50) DEFAULT NULL,
                `best_man_name` varchar(255) DEFAULT NULL,
                `best_man_size` varchar(50) DEFAULT NULL,

                -- Little Bride & Groom
                `little_bride_name` varchar(255) DEFAULT NULL,
                `little_bride_size` varchar(50) DEFAULT NULL,
                `little_groom_name` varchar(255) DEFAULT NULL,
                `little_groom_size` varchar(50) DEFAULT NULL,

                -- Wedding Party Quantities
                `bridesmaids_qty` int(11) DEFAULT 0,
                `groomsmen_qty` int(11) DEFAULT 0,
                `junior_groomsmen_qty` int(11) DEFAULT 0,
                `flower_girls_qty` int(11) DEFAULT 0,
                `ring_bearer_qty` int(11) DEFAULT 0,
                `bible_bearer_qty` int(11) DEFAULT 0,
                `coin_bearer_qty` int(11) DEFAULT 0,

                -- Wedding Party Names (stored as JSON arrays)
                `bridesmaids_names` JSON DEFAULT NULL,
                `groomsmen_names` JSON DEFAULT NULL,
                `junior_groomsmen_names` JSON DEFAULT NULL,
                `flower_girls_names` JSON DEFAULT NULL,
                `ring_bearer_names` JSON DEFAULT NULL,
                `bible_bearer_names` JSON DEFAULT NULL,
                `coin_bearer_names` JSON DEFAULT NULL,

                -- Wedding Items Quantities
                `cushions_qty` int(11) DEFAULT 0,
                `headdress_qty` int(11) DEFAULT 0,
                `shawls_qty` int(11) DEFAULT 0,
                `veil_cord_qty` int(11) DEFAULT 0,
                `basket_qty` int(11) DEFAULT 0,
                `petticoat_qty` int(11) DEFAULT 0,
                `neck_bowtie_qty` int(11) DEFAULT 0,
                `garter_leg_qty` int(11) DEFAULT 0,
                `fitting_form_qty` int(11) DEFAULT 0,
                `robe_qty` int(11) DEFAULT 0,

                -- Processing Information
                `prepared_by` varchar(255) DEFAULT NULL,
                `received_by` varchar(255) DEFAULT NULL,
                `pickup_date` date DEFAULT NULL,
                `return_date` date DEFAULT NULL,
                `customer_signature` varchar(255) DEFAULT NULL,

                -- Metadata
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_event_wedding` (`event_id`),
                KEY `idx_wedding_event_id` (`event_id`),
                KEY `idx_wedding_bride_groom` (`bride_name`, `groom_name`),
                CONSTRAINT `fk_wedding_details_event` FOREIGN KEY (`event_id`) REFERENCES `tbl_events` (`event_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $this->conn->exec($createSql);
            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Wedding details table created successfully with enhanced structure"
            ]);
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("runWeddingMigration error: " . $e->getMessage());
            return json_encode([
                "status" => "error",
                "message" => "Migration failed: " . $e->getMessage()
            ]);
        }
    }

    // Analytics and Reports Methods
    public function getAnalyticsData($adminId, $startDate = null, $endDate = null) {
        try {
            if (!$startDate) $startDate = date('Y-m-01'); // First day of current month
            if (!$endDate) $endDate = date('Y-m-t'); // Last day of current month

            $analytics = [];

            // Monthly revenue trend (last 12 months)
            $monthlyRevenueSql = "SELECT
                                    DATE_FORMAT(p.payment_date, '%Y-%m') as month,
                                    SUM(p.payment_amount) as revenue,
                                    COUNT(DISTINCT p.event_id) as events_with_payments
                                FROM tbl_payments p
                                JOIN tbl_events e ON p.event_id = e.event_id
                                WHERE e.admin_id = ?
                                AND p.payment_status = 'completed'
                                AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
                                ORDER BY month DESC";
            $stmt = $this->conn->prepare($monthlyRevenueSql);
            $stmt->execute([$adminId]);
            $analytics['monthlyRevenue'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Event types distribution
            $eventTypesSql = "SELECT
                                et.event_name,
                                COUNT(e.event_id) as count,
                                COALESCE(SUM(e.total_budget), 0) as total_budget
                            FROM tbl_events e
                            JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                            WHERE e.admin_id = ?
                            GROUP BY et.event_type_id, et.event_name
                            ORDER BY count DESC";
            $stmt = $this->conn->prepare($eventTypesSql);
            $stmt->execute([$adminId]);
            $analytics['eventTypes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Payment status breakdown
            $paymentStatusSql = "SELECT
                                   e.payment_status,
                                   COUNT(e.event_id) as count,
                                   COALESCE(SUM(e.total_budget), 0) as total_amount
                               FROM tbl_events e
                               WHERE e.admin_id = ?
                               GROUP BY e.payment_status
                               ORDER BY count DESC";
            $stmt = $this->conn->prepare($paymentStatusSql);
            $stmt->execute([$adminId]);
            $analytics['paymentStatus'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Top venues by usage
            $topVenuesSql = "SELECT
                               v.venue_title,
                               COUNT(e.event_id) as events_count,
                               COALESCE(SUM(e.total_budget), 0) as total_revenue
                           FROM tbl_events e
                           JOIN tbl_venue v ON e.venue_id = v.venue_id
                           WHERE e.admin_id = ?
                           GROUP BY v.venue_id, v.venue_title
                           ORDER BY events_count DESC
                           LIMIT 10";
            $stmt = $this->conn->prepare($topVenuesSql);
            $stmt->execute([$adminId]);
            $analytics['topVenues'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Top packages by usage
            $topPackagesSql = "SELECT
                                 p.package_title,
                                 COUNT(e.event_id) as events_count,
                                 COALESCE(SUM(e.total_budget), 0) as total_revenue
                             FROM tbl_events e
                             JOIN tbl_packages p ON e.package_id = p.package_id
                             WHERE e.admin_id = ?
                             GROUP BY p.package_id, p.package_title
                             ORDER BY events_count DESC
                             LIMIT 10";
            $stmt = $this->conn->prepare($topPackagesSql);
            $stmt->execute([$adminId]);
            $analytics['topPackages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Client statistics
            $clientStatsSql = "SELECT
                                 CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                                 COUNT(e.event_id) as events_count,
                                 COALESCE(SUM(e.total_budget), 0) as total_spent,
                                 MAX(e.created_at) as last_event_date
                             FROM tbl_events e
                             JOIN tbl_users u ON e.user_id = u.user_id
                             WHERE e.admin_id = ?
                             GROUP BY e.user_id, u.user_firstName, u.user_lastName
                             ORDER BY total_spent DESC
                             LIMIT 10";
            $stmt = $this->conn->prepare($clientStatsSql);
            $stmt->execute([$adminId]);
            $analytics['topClients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Payment method distribution
            $paymentMethodsSql = "SELECT
                                    p.payment_method,
                                    COUNT(p.payment_id) as count,
                                    COALESCE(SUM(p.payment_amount), 0) as total_amount
                                FROM tbl_payments p
                                JOIN tbl_events e ON p.event_id = e.event_id
                                WHERE e.admin_id = ? AND p.payment_status = 'completed'
                                GROUP BY p.payment_method
                                ORDER BY total_amount DESC";
            $stmt = $this->conn->prepare($paymentMethodsSql);
            $stmt->execute([$adminId]);
            $analytics['paymentMethods'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "analytics" => $analytics
            ]);
        } catch (Exception $e) {
            error_log("getAnalyticsData error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function getReportsData($adminId, $reportType = 'summary', $startDate = null, $endDate = null) {
        try {
            if (!$startDate) $startDate = date('Y-m-01');
            if (!$endDate) $endDate = date('Y-m-t');

            $reports = [];

            switch ($reportType) {
                case 'summary':
                    // Overall summary report
                    $summarySql = "SELECT
                                     COUNT(e.event_id) as total_events,
                                     COUNT(CASE WHEN e.event_status = 'completed' THEN 1 END) as completed_events,
                                     COUNT(CASE WHEN e.event_status = 'cancelled' THEN 1 END) as cancelled_events,
                                     COUNT(DISTINCT e.user_id) as unique_clients,
                                     COALESCE(SUM(e.total_budget), 0) as total_contract_value,
                                     COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) as total_revenue_collected,
                                     AVG(e.total_budget) as average_event_value
                                 FROM tbl_events e
                                 LEFT JOIN tbl_payments p ON e.event_id = p.event_id
                                 WHERE e.admin_id = ?
                                 AND e.created_at BETWEEN ? AND ?";
                    $stmt = $this->conn->prepare($summarySql);
                    $stmt->execute([$adminId, $startDate, $endDate]);
                    $reports['summary'] = $stmt->fetch(PDO::FETCH_ASSOC);
                    break;

                case 'financial':
                    // Financial detailed report
                    $financialSql = "SELECT
                                       e.event_id,
                                       e.event_title,
                                       e.event_date,
                                       CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                                       e.total_budget,
                                       e.down_payment,
                                       e.payment_status,
                                       COALESCE(SUM(p.payment_amount), 0) as total_paid,
                                       (e.total_budget - COALESCE(SUM(p.payment_amount), 0)) as remaining_balance
                                   FROM tbl_events e
                                   JOIN tbl_users u ON e.user_id = u.user_id
                                   LEFT JOIN tbl_payments p ON e.event_id = p.event_id AND p.payment_status = 'completed'
                                   WHERE e.admin_id = ?
                                   AND e.created_at BETWEEN ? AND ?
                                   GROUP BY e.event_id
                                   ORDER BY e.event_date DESC";
                    $stmt = $this->conn->prepare($financialSql);
                    $stmt->execute([$adminId, $startDate, $endDate]);
                    $reports['financial'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'events':
                    // Events detailed report
                    $eventsSql = "SELECT
                                    e.event_id,
                                    e.event_title,
                                    e.event_date,
                                    et.event_name as event_type,
                                    CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                                    v.venue_title,
                                    p.package_title,
                                    e.guest_count,
                                    e.total_budget,
                                    e.event_status,
                                    e.payment_status,
                                    e.created_at
                                FROM tbl_events e
                                JOIN tbl_users u ON e.user_id = u.user_id
                                JOIN tbl_event_type et ON e.event_type_id = et.event_type_id
                                LEFT JOIN tbl_venue v ON e.venue_id = v.venue_id
                                LEFT JOIN tbl_packages p ON e.package_id = p.package_id
                                WHERE e.admin_id = ?
                                AND e.created_at BETWEEN ? AND ?
                                ORDER BY e.event_date DESC";
                    $stmt = $this->conn->prepare($eventsSql);
                    $stmt->execute([$adminId, $startDate, $endDate]);
                    $reports['events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'clients':
                    // Clients detailed report
                    $clientsSql = "SELECT
                                     u.user_id,
                                     CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                                     u.user_email,
                                     u.user_contact,
                                     COUNT(e.event_id) as total_events,
                                     COALESCE(SUM(e.total_budget), 0) as total_contract_value,
                                     COALESCE(SUM(p.payment_amount), 0) as total_payments,
                                     MIN(e.created_at) as first_event_date,
                                     MAX(e.created_at) as last_event_date
                                 FROM tbl_users u
                                 JOIN tbl_events e ON u.user_id = e.user_id
                                 LEFT JOIN tbl_payments p ON e.event_id = p.event_id AND p.payment_status = 'completed'
                                 WHERE e.admin_id = ?
                                 AND e.created_at BETWEEN ? AND ?
                                 GROUP BY u.user_id
                                 ORDER BY total_contract_value DESC";
                    $stmt = $this->conn->prepare($clientsSql);
                    $stmt->execute([$adminId, $startDate, $endDate]);
                    $reports['clients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;
            }

            return json_encode([
                "status" => "success",
                "reports" => $reports,
                "reportType" => $reportType,
                "dateRange" => [
                    "startDate" => $startDate,
                    "endDate" => $endDate
                ]
            ]);
        } catch (Exception $e) {
            error_log("getReportsData error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    // New method to associate payment proof with a specific payment
    public function attachPaymentProof($paymentId, $file, $description, $proofType) {
        try {
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                return json_encode(["status" => "error", "message" => "File upload error"]);
            }

            // Validate file size (max 10MB)
            if ($file['size'] > 10 * 1024 * 1024) {
                return json_encode(["status" => "error", "message" => "File too large. Maximum 10MB allowed."]);
            }

            // Validate file type (expanded to support more document types)
            $allowedTypes = [
                // Images
                'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
                // Documents
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                // Text files
                'text/plain', 'text/csv',
                // Other common formats
                'application/zip', 'application/x-zip-compressed'
            ];
            if (!in_array($file['type'], $allowedTypes)) {
                return json_encode(["status" => "error", "message" => "Invalid file type. Allowed: Images, PDF, Word, Excel, PowerPoint, Text, and ZIP files."]);
            }

            // Check if payment exists
            $checkSql = "SELECT payment_id, event_id, payment_attachments FROM tbl_payments WHERE payment_id = ?";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->execute([$paymentId]);
            $payment = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                return json_encode(["status" => "error", "message" => "Payment not found"]);
            }

            // Create payment proofs directory
            $uploadDir = "uploads/payment_proofs/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = "payment_proof_p{$paymentId}_" . time() . '_' . uniqid() . '.' . $extension;
            $targetPath = $uploadDir . $filename;

            // Move the uploaded file
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                // Get current payment attachments
                $attachments = [];
                if ($payment['payment_attachments']) {
                    $attachments = json_decode($payment['payment_attachments'], true) ?: [];
                }

                // Add new attachment
                $newAttachment = [
                    'file_name' => $filename,
                    'original_name' => $file['name'],
                    'file_path' => $targetPath,
                    'file_size' => $file['size'],
                    'file_type' => $file['type'],
                    'description' => $description,
                    'proof_type' => $proofType,
                    'uploaded_at' => date('Y-m-d H:i:s'),
                ];

                $attachments[] = $newAttachment;

                // Update payment with new attachments
                $updateSql = "UPDATE tbl_payments SET payment_attachments = ? WHERE payment_id = ?";
                $updateStmt = $this->conn->prepare($updateSql);
                $updateStmt->execute([json_encode($attachments), $paymentId]);

                return json_encode([
                    "status" => "success",
                    "message" => "Payment proof attached successfully",
                    "attachment" => $newAttachment
                ]);
            } else {
                return json_encode(["status" => "error", "message" => "Failed to move uploaded file"]);
            }

        } catch (Exception $e) {
            error_log("attachPaymentProof error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Attachment error: " . $e->getMessage()]);
        }
    }

        public function getEventsForPayments($adminId, $searchTerm = '') {
        try {
            $sql = "
                SELECT
                    e.event_id,
                    e.event_title,
                    e.event_date,
                    e.user_id as client_id,
                    e.total_budget,
                    e.payment_status as event_payment_status,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                    u.user_email as client_email,
                    u.user_contact as client_contact,
                    COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) as total_paid,
                    (e.total_budget - COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0)) as remaining_balance,
                    CASE
                        WHEN e.total_budget > 0 THEN ROUND((COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) / e.total_budget) * 100, 2)
                        ELSE 0
                    END as payment_percentage,
                    COUNT(CASE WHEN p.payment_status != 'cancelled' THEN p.payment_id END) as payment_count
                FROM tbl_events e
                LEFT JOIN tbl_users u ON e.user_id = u.user_id
                LEFT JOIN tbl_payments p ON e.event_id = p.event_id AND p.payment_status != 'cancelled'
                WHERE e.admin_id = ?
            ";

            $params = [$adminId];

            if (!empty($searchTerm)) {
                $sql .= " AND (
                    e.event_title LIKE ? OR
                    e.event_id LIKE ? OR
                    CONCAT(u.user_firstName, ' ', u.user_lastName) LIKE ? OR
                    u.user_email LIKE ?
                )";
                $searchParam = '%' . $searchTerm . '%';
                $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
            }

            $sql .= " GROUP BY e.event_id ORDER BY e.event_date DESC, e.event_title ASC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "events" => $events
            ]);

        } catch (Exception $e) {
            error_log("getEventsForPayments error: " . $e->getMessage());
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch events: " . $e->getMessage()
            ]);
        }
    }

    public function getEventPaymentDetails($eventId) {
        try {
            // Get event details with payment summary
            $eventQuery = "
                SELECT
                    e.event_id,
                    e.event_title,
                    e.event_date,
                    e.event_time,
                    e.user_id as client_id,
                    e.total_budget,
                    e.payment_status as event_payment_status,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                    u.user_email as client_email,
                    u.user_contact as client_contact,
                    COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) as total_paid,
                    (e.total_budget - COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0)) as remaining_balance,
                    CASE
                        WHEN e.total_budget > 0 THEN ROUND((COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END), 0) / e.total_budget) * 100, 2)
                        ELSE 0
                    END as payment_percentage,
                    COUNT(p.payment_id) as total_payments,
                    COUNT(CASE WHEN p.payment_status = 'completed' THEN 1 END) as completed_payments,
                    COUNT(CASE WHEN p.payment_status = 'pending' THEN 1 END) as pending_payments
                FROM tbl_events e
                LEFT JOIN tbl_users u ON e.user_id = u.user_id
                LEFT JOIN tbl_payments p ON e.event_id = p.event_id
                WHERE e.event_id = ?
                GROUP BY e.event_id
            ";

            $stmt = $this->pdo->prepare($eventQuery);
            $stmt->execute([$eventId]);
            $eventDetails = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$eventDetails) {
                return json_encode([
                    "status" => "error",
                    "message" => "Event not found"
                ]);
            }

                        // Get payment history for this event (exclude cancelled payments)
            $paymentsQuery = "
                SELECT
                    p.payment_id,
                    p.payment_amount,
                    p.payment_method,
                    p.payment_status,
                    p.payment_date,
                    p.payment_reference,
                    p.payment_notes,
                    p.payment_attachments,
                    p.created_at,
                    p.updated_at,
                    CONCAT(u.user_firstName, ' ', u.user_lastName) as client_name,
                    DATE_FORMAT(p.created_at, '%Y-%m-%d %H:%i:%s') as formatted_created_at,
                    DATE_FORMAT(p.updated_at, '%Y-%m-%d %H:%i:%s') as formatted_updated_at
                FROM tbl_payments p
                LEFT JOIN tbl_users u ON p.client_id = u.user_id
                WHERE p.event_id = ? AND p.payment_status != 'cancelled'
                ORDER BY p.created_at DESC, p.payment_date DESC
            ";

            $stmt = $this->pdo->prepare($paymentsQuery);
            $stmt->execute([$eventId]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Parse payment attachments for each payment
            foreach ($payments as &$payment) {
                if (!empty($payment['payment_attachments'])) {
                    $payment['attachments'] = json_decode($payment['payment_attachments'], true);
                } else {
                    $payment['attachments'] = [];
                }
            }

            // Get payment summary by method
            $paymentSummaryQuery = "
                SELECT
                    p.payment_method,
                    COUNT(*) as payment_count,
                    SUM(CASE WHEN p.payment_status = 'completed' THEN p.payment_amount ELSE 0 END) as total_amount
                FROM tbl_payments p
                WHERE p.event_id = ?
                GROUP BY p.payment_method
                ORDER BY total_amount DESC
            ";

            $stmt = $this->pdo->prepare($paymentSummaryQuery);
            $stmt->execute([$eventId]);
            $paymentSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "event" => $eventDetails,
                "payments" => $payments,
                "payment_summary" => $paymentSummary
            ]);

        } catch (Exception $e) {
            error_log("getEventPaymentDetails error: " . $e->getMessage());
            return json_encode([
                "status" => "error",
                "message" => "Failed to fetch event payment details: " . $e->getMessage()
            ]);
        }
    }

    public function uploadPaymentAttachment($eventId, $paymentId, $file, $description = '') {
        try {
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/events-api/uploads/payment_proof/';

            // Create directory if it doesn't exist
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];

            if (!in_array($fileExtension, $allowedExtensions)) {
                return json_encode([
                    "status" => "error",
                    "message" => "Invalid file type. Allowed: " . implode(', ', $allowedExtensions)
                ]);
            }

            $fileName = time() . '_' . uniqid() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // Get current payment attachments
                $stmt = $this->pdo->prepare("SELECT payment_attachments FROM tbl_payments WHERE payment_id = ?");
                $stmt->execute([$paymentId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                $attachments = [];
                if (!empty($result['payment_attachments'])) {
                    $attachments = json_decode($result['payment_attachments'], true) ?: [];
                }

                // Add new attachment
                $attachments[] = [
                    'filename' => $fileName,
                    'original_name' => $file['name'],
                    'description' => $description,
                    'file_size' => $file['size'],
                    'file_type' => $fileExtension,
                    'uploaded_at' => date('Y-m-d H:i:s')
                ];

                // Update payment with new attachments
                $updateStmt = $this->pdo->prepare("
                    UPDATE tbl_payments
                    SET payment_attachments = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE payment_id = ?
                ");
                $updateStmt->execute([json_encode($attachments), $paymentId]);

                return json_encode([
                    "status" => "success",
                    "filename" => $fileName,
                    "message" => "Payment attachment uploaded successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "Failed to upload file"
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                "status" => "error",
                "message" => "Failed to upload payment attachment: " . $e->getMessage()
            ]);
        }
    }

    public function updateVenueStatus($venueId, $isActive) {
        try {
            $sql = "UPDATE tbl_venue SET is_active = :is_active WHERE venue_id = :venue_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'is_active' => $isActive ? 1 : 0,
                'venue_id' => $venueId
            ]);

            if ($stmt->rowCount() > 0) {
                return json_encode([
                    "status" => "success",
                    "message" => "Venue status updated successfully"
                ]);
            } else {
                return json_encode([
                    "status" => "error",
                    "message" => "No venue found with the given ID"
                ]);
            }
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }


    public function updateVenueWithPriceHistory() {
        try {
            $this->pdo->beginTransaction();

            // Get current venue data
            $stmt = $this->pdo->prepare("SELECT venue_price FROM tbl_venue WHERE venue_id = ?");
            $stmt->execute([$_POST['venue_id']]);
            $currentVenue = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentVenue) {
                throw new Exception("Venue not found");
            }

            // If price is changing, record it in price history
            if (floatval($currentVenue['venue_price']) != floatval($_POST['venue_price'])) {
                $sql = "INSERT INTO tbl_venue_price_history (
                    venue_id, old_price, new_price
                ) VALUES (
                    :venue_id, :old_price, :new_price
                )";

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    ':venue_id' => $_POST['venue_id'],
                    ':old_price' => $currentVenue['venue_price'],
                    ':new_price' => $_POST['venue_price']
                ]);
            }

            // Handle file uploads
            $profilePicture = null;
            $coverPhoto = null;

            if (isset($_FILES['venue_profile_picture']) && $_FILES['venue_profile_picture']['error'] === 0) {
                $profilePicture = $this->uploadFile($_FILES['venue_profile_picture'], 'venue_profile_pictures');
            }

            if (isset($_FILES['venue_cover_photo']) && $_FILES['venue_cover_photo']['error'] === 0) {
                $coverPhoto = $this->uploadFile($_FILES['venue_cover_photo'], 'venue_cover_photos');
            }

            // Update venue basic info
            $sql = "UPDATE tbl_venue SET
                venue_title = :venue_title,
                venue_details = :venue_details,
                venue_location = :venue_location,
                venue_contact = :venue_contact,
                venue_capacity = :venue_capacity,
                venue_price = :venue_price";

            // Only include files in update if they were uploaded
            if ($profilePicture) {
                $sql .= ", venue_profile_picture = :venue_profile_picture";
            }
            if ($coverPhoto) {
                $sql .= ", venue_cover_photo = :venue_cover_photo";
            }

            $sql .= " WHERE venue_id = :venue_id";

            $stmt = $this->pdo->prepare($sql);

            $params = [
                ':venue_title' => $_POST['venue_title'],
                ':venue_details' => $_POST['venue_details'],
                ':venue_location' => $_POST['venue_location'],
                ':venue_contact' => $_POST['venue_contact'],
                ':venue_capacity' => $_POST['venue_capacity'],
                ':venue_price' => $_POST['venue_price'],
                ':venue_id' => $_POST['venue_id']
            ];

            if ($profilePicture) {
                $params[':venue_profile_picture'] = $profilePicture;
            }
            if ($coverPhoto) {
                $params[':venue_cover_photo'] = $coverPhoto;
            }

            $stmt->execute($params);

            $this->pdo->commit();

            return json_encode([
                "status" => "success",
                "message" => "Venue updated successfully"
            ]);

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return json_encode([
                "status" => "error",
                "message" => "Failed to update venue: " . $e->getMessage()
            ]);
        }
    }

    // Event Component Management Methods
    public function addEventComponent($data) {
        try {
            $required = ['event_id', 'component_name', 'component_price'];
            foreach ($required as $field) {
                if (!isset($data[$field])) {
                    return json_encode(["status" => "error", "message" => "$field is required"]);
                }
            }

            $sql = "INSERT INTO tbl_event_components (
                        event_id, component_name, component_description,
                        component_price, is_custom, is_included,
                        original_package_component_id, supplier_id, offer_id, display_order
                    ) VALUES (
                        :event_id, :name, :description,
                        :price, :is_custom, :is_included,
                        :original_package_component_id, :supplier_id, :offer_id, :display_order
                    )";

            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                ':event_id' => $data['event_id'],
                ':name' => $data['component_name'],
                ':description' => $data['component_description'] ?? null,
                ':price' => $data['component_price'],
                ':is_custom' => $data['is_custom'] ?? true,
                ':is_included' => $data['is_included'] ?? true,
                ':original_package_component_id' => $data['original_package_component_id'] ?? null,
                ':supplier_id' => $data['supplier_id'] ?? null,
                ':offer_id' => $data['offer_id'] ?? null,
                ':display_order' => $data['display_order'] ?? 0
            ]);

            if ($result) {
                $componentId = $this->conn->lastInsertId();
                return json_encode([
                    "status" => "success",
                    "message" => "Component added successfully",
                    "component_id" => $componentId
                ]);
            } else {
                return json_encode(["status" => "error", "message" => "Failed to add component"]);
            }
        } catch (Exception $e) {
            error_log("addEventComponent error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function updateEventComponent($data) {
        try {
            $required = ['component_id', 'component_name', 'component_price'];
            foreach ($required as $field) {
                if (!isset($data[$field])) {
                    return json_encode(["status" => "error", "message" => "$field is required"]);
                }
            }

            // Fetch the current event component
            $sql = "SELECT * FROM tbl_event_components WHERE component_id = :component_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':component_id' => $data['component_id']]);
            $eventComponent = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$eventComponent) {
                return json_encode(["status" => "error", "message" => "Component not found"]);
            }

            // If not custom, enforce no downgrade
            if (!$eventComponent['is_custom'] && $eventComponent['original_package_component_id']) {
                $sql = "SELECT component_price FROM tbl_package_components WHERE component_id = :original_id";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([':original_id' => $eventComponent['original_package_component_id']]);
                $original = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($original && floatval($data['component_price']) < floatval($original['component_price'])) {
                    return json_encode(["status" => "error", "message" => "Cannot downgrade inclusion price below original package value (₱" . number_format($original['component_price'],2) . ")"]);
                }
            }

            $sql = "UPDATE tbl_event_components SET
                        component_name = :name,
                        component_description = :description,
                        component_price = :price,
                        is_included = :is_included
                    WHERE component_id = :component_id";

            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                ':component_id' => $data['component_id'],
                ':name' => $data['component_name'],
                ':description' => $data['component_description'] ?? null,
                ':price' => $data['component_price'],
                ':is_included' => $data['is_included'] ?? true
            ]);

            if ($result) {
                return json_encode([
                    "status" => "success",
                    "message" => "Component updated successfully"
                ]);
            } else {
                return json_encode(["status" => "error", "message" => "Failed to update component"]);
            }
        } catch (Exception $e) {
            error_log("updateEventComponent error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function deleteEventComponent($componentId) {
        try {
            if (empty($componentId)) {
                return json_encode(["status" => "error", "message" => "Component ID is required"]);
            }

            $sql = "DELETE FROM tbl_event_components WHERE component_id = :component_id";
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([':component_id' => $componentId]);

            if ($result) {
                return json_encode([
                    "status" => "success",
                    "message" => "Component deleted successfully"
                ]);
            } else {
                return json_encode(["status" => "error", "message" => "Failed to delete component"]);
            }
        } catch (Exception $e) {
            error_log("deleteEventComponent error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    public function updateEventBudget($eventId, $budgetChange) {
        try {
            // Get current budget
            $sql = "SELECT total_budget FROM tbl_events WHERE event_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$eventId]);
            $currentBudget = $stmt->fetch(PDO::FETCH_ASSOC)['total_budget'];

            // Calculate new budget
            $newBudget = $currentBudget + $budgetChange;

            // Update event budget
            $updateSql = "UPDATE tbl_events SET total_budget = ? WHERE event_id = ?";
            $updateStmt = $this->pdo->prepare($updateSql);
            $updateStmt->execute([$newBudget, $eventId]);

            return json_encode([
                "status" => "success",
                "message" => "Event budget updated successfully",
                "old_budget" => $currentBudget,
                "new_budget" => $newBudget,
                "change" => $budgetChange
            ]);
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    public function getPackageVenues($packageId) {
        try {
            $sql = "SELECT v.*, pv.package_id
                    FROM tbl_venue v
                    INNER JOIN tbl_package_venues pv ON v.venue_id = pv.venue_id
                    WHERE pv.package_id = ? AND v.is_active = 1
                    ORDER BY v.venue_title ASC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$packageId]);
            $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "venues" => $venues
            ]);
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    public function updateEventVenue($eventId, $venueId) {
        try {
            // First check if the venue is available for the event's package
            $checkSql = "SELECT e.package_id, pv.venue_id
                        FROM tbl_events e
                        INNER JOIN tbl_package_venues pv ON e.package_id = pv.package_id
                        WHERE e.event_id = ? AND pv.venue_id = ?";

            $checkStmt = $this->pdo->prepare($checkSql);
            $checkStmt->execute([$eventId, $venueId]);

            if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
                return json_encode([
                    "status" => "error",
                    "message" => "Selected venue is not available for this package"
                ]);
            }

            // Update the event venue
            $updateSql = "UPDATE tbl_events SET venue_id = ? WHERE event_id = ?";
            $updateStmt = $this->pdo->prepare($updateSql);
            $updateStmt->execute([$venueId, $eventId]);

            // Get venue details for response
            $venueSql = "SELECT venue_id, venue_title, venue_location, venue_price FROM tbl_venue WHERE venue_id = ?";
            $venueStmt = $this->pdo->prepare($venueSql);
            $venueStmt->execute([$venueId]);
            $venue = $venueStmt->fetch(PDO::FETCH_ASSOC);

            return json_encode([
                "status" => "success",
                "message" => "Event venue updated successfully",
                "venue" => $venue
            ]);
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    public function updateEventFinalization($eventId, $action) {
        try {
            // Get event details first
            $eventSql = "SELECT e.*, u.email as client_email, u.first_name, u.last_name
                        FROM tbl_events e
                        LEFT JOIN tbl_users u ON e.user_id = u.user_id
                        WHERE e.event_id = ?";
            $eventStmt = $this->pdo->prepare($eventSql);
            $eventStmt->execute([$eventId]);
            $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

            if (!$event) {
                return json_encode([
                    "status" => "error",
                    "message" => "Event not found"
                ]);
            }

            if ($action === "lock") {
                // Finalize and lock the event
                $updateSql = "UPDATE tbl_events SET
                             event_status = 'finalized',
                             finalized_at = NOW(),
                             updated_at = NOW()
                             WHERE event_id = ?";
                $updateStmt = $this->pdo->prepare($updateSql);
                $updateStmt->execute([$eventId]);

                // Send notification to organizer about upcoming payments
                $this->sendFinalizationNotification($event);

                return json_encode([
                    "status" => "success",
                    "message" => "Event has been finalized and locked for editing",
                    "finalized_at" => date('Y-m-d H:i:s')
                ]);
            } else {
                // Unlock the event
                $updateSql = "UPDATE tbl_events SET
                             event_status = 'confirmed',
                             finalized_at = NULL,
                             updated_at = NOW()
                             WHERE event_id = ?";
                $updateStmt = $this->pdo->prepare($updateSql);
                $updateStmt->execute([$eventId]);

                return json_encode([
                    "status" => "success",
                    "message" => "Event has been unlocked for editing"
                ]);
            }
        } catch (PDOException $e) {
            return json_encode([
                "status" => "error",
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    private function sendFinalizationNotification($event) {
        try {
            // Create notification for the organizer
            $notificationSql = "INSERT INTO tbl_notifications (
                user_id,
                event_id,
                notification_type,
                notification_title,
                notification_message,
                created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())";

            $notificationStmt = $this->pdo->prepare($notificationSql);
            $notificationStmt->execute([
                $event['user_id'],
                $event['event_id'],
                'event_finalized',
                'Event Finalized',
                "Your event '{$event['event_title']}' has been finalized. Please check your payment schedule for upcoming payments."
            ]);

            // Log the finalization activity
            $logSql = "INSERT INTO tbl_payment_logs (
                event_id,
                client_id,
                action_type,
                notes,
                created_at
            ) VALUES (?, ?, ?, ?, NOW())";

            $logStmt = $this->pdo->prepare($logSql);
            $logStmt->execute([
                $event['event_id'],
                $event['user_id'],
                'event_finalized',
                'Event finalized by admin. Organizer notified about payment schedule.'
            ]);

        } catch (Exception $e) {
            error_log("Error sending finalization notification: " . $e->getMessage());
        }
    }

    /**
     * Calculate package budget breakdown including buffer/overage
     */
    public function getPackageBudgetBreakdown($packageId) {
        try {
            // Get package details
            $packageSql = "SELECT package_id, package_title, package_price, original_price, is_price_locked FROM tbl_packages WHERE package_id = :package_id";
            $packageStmt = $this->conn->prepare($packageSql);
            $packageStmt->execute([':package_id' => $packageId]);
            $package = $packageStmt->fetch(PDO::FETCH_ASSOC);

            if (!$package) {
                return json_encode(["status" => "error", "message" => "Package not found"]);
            }

            // Get components total
            $componentsSql = "SELECT COALESCE(SUM(component_price), 0) as total_cost FROM tbl_package_components WHERE package_id = :package_id";
            $componentsStmt = $this->conn->prepare($componentsSql);
            $componentsStmt->execute([':package_id' => $packageId]);
            $componentsTotal = floatval($componentsStmt->fetchColumn());

            $packagePrice = floatval($package['package_price']);
            $difference = $packagePrice - $componentsTotal;

            $budgetStatus = 'EXACT';
            if ($difference > 0) {
                $budgetStatus = 'BUFFER';
            } elseif ($difference < 0) {
                $budgetStatus = 'OVERAGE';
            }

            return json_encode([
                "status" => "success",
                "budget_breakdown" => [
                    "package_id" => $package['package_id'],
                    "package_title" => $package['package_title'],
                    "package_price" => $packagePrice,
                    "original_price" => floatval($package['original_price']),
                    "is_price_locked" => boolval($package['is_price_locked']),
                    "inclusions_total" => $componentsTotal,
                    "difference" => $difference,
                    "difference_absolute" => abs($difference),
                    "budget_status" => $budgetStatus,
                    "buffer_amount" => $difference > 0 ? $difference : 0,
                    "overage_amount" => $difference < 0 ? abs($difference) : 0,
                    "margin_percentage" => $packagePrice > 0 ? ($difference / $packagePrice) * 100 : 0
                ]
            ]);
        } catch (Exception $e) {
            error_log("getPackageBudgetBreakdown error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    /**
     * Validate package budget before saving
     */
    public function validatePackageBudget($packageId, $components) {
        try {
            // Get package price
            $packageSql = "SELECT package_price, is_price_locked FROM tbl_packages WHERE package_id = :package_id";
            $packageStmt = $this->conn->prepare($packageSql);
            $packageStmt->execute([':package_id' => $packageId]);
            $package = $packageStmt->fetch(PDO::FETCH_ASSOC);

            if (!$package) {
                return json_encode(["status" => "error", "message" => "Package not found"]);
            }

            // Calculate total component cost
            $totalComponentCost = 0;
            foreach ($components as $component) {
                $totalComponentCost += floatval($component['component_price'] ?? 0);
            }

            $packagePrice = floatval($package['package_price']);
            $difference = $packagePrice - $totalComponentCost;

            $validation = [
                "is_valid" => true,
                "package_price" => $packagePrice,
                "inclusions_total" => $totalComponentCost,
                "difference" => $difference,
                "warnings" => [],
                "errors" => []
            ];

            // Check for overage
            if ($difference < 0) {
                $validation["warnings"][] = [
                    "type" => "overage",
                    "message" => "Budget overage detected: Inclusions exceed package price by ₱" . number_format(abs($difference), 2),
                    "overage_amount" => abs($difference)
                ];
            }

            // Check if price is locked and cannot be reduced
            if (boolval($package['is_price_locked'])) {
                $validation["is_price_locked"] = true;
                $validation["price_lock_message"] = "Package price is locked and cannot be reduced";
            }

            return json_encode([
                "status" => "success",
                "validation" => $validation
            ]);
        } catch (Exception $e) {
            error_log("validatePackageBudget error: " . $e->getMessage());
            return json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }

    // =============================================================================
    // ORGANIZER MANAGEMENT METHODS
    // =============================================================================

    public function createOrganizer($data) {
        try {
            $this->conn->beginTransaction();

            // Validate required fields
            $required = ['first_name', 'last_name', 'email', 'phone', 'username', 'password'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("$field is required");
                }
            }

            // Validate email format
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format");
            }

            // Check for duplicate email or username
            $checkStmt = $this->conn->prepare("SELECT user_id FROM tbl_users WHERE user_email = ? OR user_username = ?");
            $checkStmt->execute([$data['email'], $data['username']]);
            if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception("An account with this email or username already exists");
            }

            // Hash password
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

            // Handle profile picture - use the provided path or default
            $profilePicturePath = !empty($data['profile_picture']) ? $data['profile_picture'] : null;

            // Create user account
            $userSql = "INSERT INTO tbl_users (
                           user_firstName, user_lastName, user_suffix, user_birthdate,
                           user_email, user_contact, user_username, user_pwd,
                           user_role, user_pfp, account_status, created_at
                       ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'organizer', ?, 'active', NOW())";

            $userStmt = $this->conn->prepare($userSql);
            $userStmt->execute([
                $data['first_name'],
                $data['last_name'],
                $data['suffix'] ?? null,
                $data['date_of_birth'] ?? null,
                $data['email'],
                $data['phone'],
                $data['username'],
                $hashedPassword,
                $profilePicturePath
            ]);

            $userId = $this->conn->lastInsertId();

            // Handle resume path - use the provided path
            $resumePath = !empty($data['resume_path']) ? $data['resume_path'] : null;

            // Handle certification files (JSON array)
            $certificationFilesJson = null;
            if (!empty($data['certification_files']) && is_array($data['certification_files'])) {
                $certificationFilesJson = json_encode($data['certification_files']);
            }

            // Create experience summary
            $experienceSummary = "Years of Experience: " . ($data['years_of_experience'] ?? 0);
            if (!empty($data['address'])) {
                $experienceSummary .= "\nAddress: " . $data['address'];
            }

            // Create organizer record (using actual database column names)
            $organizerSql = "INSERT INTO tbl_organizer (
                                user_id, organizer_experience, organizer_certifications,
                                organizer_resume_path, organizer_portfolio_link,
                                organizer_availability, remarks, created_at
                            ) VALUES (?, ?, ?, ?, ?, 'flexible', ?, NOW())";

            $organizerStmt = $this->conn->prepare($organizerSql);
            $organizerStmt->execute([
                $userId,
                $experienceSummary,
                $certificationFilesJson,
                $resumePath,
                $data['portfolio_link'] ?? null,
                $data['admin_remarks'] ?? null
            ]);

            $organizerId = $this->conn->lastInsertId();

            $this->conn->commit();

            // Send welcome email (outside transaction)
            try {
                $this->sendOrganizerWelcomeEmail($data['email'], $data['first_name'] . ' ' . $data['last_name'], $data['password'], $organizerId);
            } catch (Exception $emailError) {
                // Log email error but don't fail the organizer creation
                error_log("Failed to send welcome email: " . $emailError->getMessage());
            }

            // Log activity (outside transaction)
            try {
                $this->logOrganizerActivity($organizerId, 'created', 'Organizer account created', $userId);
            } catch (Exception $logError) {
                // Log activity error but don't fail the organizer creation
                error_log("Failed to log activity: " . $logError->getMessage());
            }

            return json_encode([
                "status" => "success",
                "message" => "Organizer created successfully",
                "data" => [
                    "organizer_id" => $organizerId,
                    "user_id" => $userId,
                    "email" => $data['email'],
                    "username" => $data['username']
                ]
            ]);

        } catch (Exception $e) {
            // Check if transaction is active before rolling back
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return json_encode(["status" => "error", "message" => "Error creating organizer: " . $e->getMessage()]);
        }
    }

    public function getAllOrganizers($page = 1, $limit = 20, $filters = []) {
        try {
            $offset = ($page - 1) * $limit;
            $conditions = ["u.user_role = 'organizer'"];
            $params = [];

            // Apply filters
            if (!empty($filters['search'])) {
                $conditions[] = "(u.user_firstName LIKE ? OR u.user_lastName LIKE ? OR u.user_email LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            if (!empty($filters['availability'])) {
                $conditions[] = "o.organizer_availability = ?";
                $params[] = $filters['availability'];
            }

            if (isset($filters['is_active']) && $filters['is_active'] !== '') {
                $conditions[] = "u.account_status = ?";
                $params[] = $filters['is_active'] === 'true' ? 'active' : 'inactive';
            }

            $whereClause = implode(' AND ', $conditions);

            // Get total count
            $countSql = "SELECT COUNT(*) FROM tbl_users u
                         LEFT JOIN tbl_organizer o ON u.user_id = o.user_id
                         WHERE $whereClause";
            $countStmt = $this->conn->prepare($countSql);
            $countStmt->execute($params);
            $totalCount = $countStmt->fetchColumn();

            // Get organizers
            $sql = "SELECT
                        u.user_id, u.user_firstName, u.user_lastName, u.user_suffix,
                        u.user_birthdate, u.user_email, u.user_contact, u.user_username,
                        u.user_pfp, u.account_status, u.created_at,
                        o.organizer_id, o.organizer_experience, o.organizer_certifications,
                        o.organizer_resume_path, o.organizer_portfolio_link,
                        o.organizer_availability, o.remarks
                    FROM tbl_users u
                    LEFT JOIN tbl_organizer o ON u.user_id = o.user_id
                    WHERE $whereClause
                    ORDER BY u.created_at DESC
                    LIMIT ? OFFSET ?";

            $params[] = $limit;
            $params[] = $offset;

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $organizers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format data
            $formattedOrganizers = array_map(function($organizer) {
                return [
                    'organizer_id' => $organizer['organizer_id'],
                    'user_id' => $organizer['user_id'],
                    'first_name' => $organizer['user_firstName'],
                    'last_name' => $organizer['user_lastName'],
                    'suffix' => $organizer['user_suffix'],
                    'birthdate' => $organizer['user_birthdate'],
                    'email' => $organizer['user_email'],
                    'contact_number' => $organizer['user_contact'],
                    'username' => $organizer['user_username'],
                    'profile_picture' => $organizer['user_pfp'],
                    'is_active' => $organizer['account_status'] === 'active',
                    'experience_summary' => $organizer['organizer_experience'],
                    'certifications' => $organizer['organizer_certifications'],
                    'resume_path' => $organizer['organizer_resume_path'],
                    'portfolio_link' => $organizer['organizer_portfolio_link'],
                    'availability' => $organizer['organizer_availability'],
                    'remarks' => $organizer['remarks'],
                    'created_at' => $organizer['created_at']
                ];
            }, $organizers);

            return json_encode([
                "status" => "success",
                "data" => [
                    "organizers" => $formattedOrganizers,
                    "pagination" => [
                        "current_page" => $page,
                        "total_pages" => ceil($totalCount / $limit),
                        "total_count" => $totalCount,
                        "per_page" => $limit
                    ]
                ]
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => "Error fetching organizers: " . $e->getMessage()]);
        }
    }

    public function getOrganizerById($organizerId) {
        try {
            $sql = "SELECT
                        u.user_id, u.user_firstName, u.user_lastName, u.user_suffix,
                        u.user_birthdate, u.user_email, u.user_contact, u.user_username,
                        u.user_pfp, u.account_status, u.created_at,
                        o.organizer_id, o.organizer_experience, o.organizer_certifications,
                        o.organizer_resume_path, o.organizer_portfolio_link,
                        o.organizer_availability, o.remarks
                    FROM tbl_users u
                    INNER JOIN tbl_organizer o ON u.user_id = o.user_id
                    WHERE o.organizer_id = ?";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$organizerId]);
            $organizer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$organizer) {
                return json_encode(["status" => "error", "message" => "Organizer not found"]);
            }

            $formattedOrganizer = [
                'organizer_id' => $organizer['organizer_id'],
                'user_id' => $organizer['user_id'],
                'first_name' => $organizer['user_firstName'],
                'last_name' => $organizer['user_lastName'],
                'suffix' => $organizer['user_suffix'],
                'birthdate' => $organizer['user_birthdate'],
                'email' => $organizer['user_email'],
                'contact_number' => $organizer['user_contact'],
                'username' => $organizer['user_username'],
                'profile_picture' => $organizer['user_pfp'],
                'is_active' => $organizer['account_status'] === 'active',
                'experience_summary' => $organizer['organizer_experience'],
                'certifications' => $organizer['organizer_certifications'],
                'resume_path' => $organizer['organizer_resume_path'],
                'portfolio_link' => $organizer['organizer_portfolio_link'],
                'availability' => $organizer['organizer_availability'],
                'remarks' => $organizer['remarks'],
                'created_at' => $organizer['created_at']
            ];

            return json_encode([
                "status" => "success",
                "data" => $formattedOrganizer
            ]);

        } catch (Exception $e) {
            return json_encode(["status" => "error", "message" => "Error fetching organizer: " . $e->getMessage()]);
        }
    }

    public function updateOrganizer($organizerId, $data) {
        try {
            $this->conn->beginTransaction();

            // Get existing organizer
            $existingStmt = $this->conn->prepare("SELECT user_id FROM tbl_organizer WHERE organizer_id = ?");
            $existingStmt->execute([$organizerId]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                throw new Exception("Organizer not found");
            }

            $userId = $existing['user_id'];

            // Update user data
            if (isset($data['first_name']) || isset($data['last_name']) || isset($data['email']) || isset($data['contact_number'])) {
                $userFields = [];
                $userParams = [];

                if (isset($data['first_name'])) {
                    $userFields[] = "user_firstName = ?";
                    $userParams[] = $data['first_name'];
                }
                if (isset($data['last_name'])) {
                    $userFields[] = "user_lastName = ?";
                    $userParams[] = $data['last_name'];
                }
                if (isset($data['suffix'])) {
                    $userFields[] = "user_suffix = ?";
                    $userParams[] = $data['suffix'];
                }
                if (isset($data['birthdate'])) {
                    $userFields[] = "user_birthdate = ?";
                    $userParams[] = $data['birthdate'];
                }
                if (isset($data['email'])) {
                    $userFields[] = "user_email = ?";
                    $userParams[] = $data['email'];
                }
                if (isset($data['contact_number'])) {
                    $userFields[] = "user_contact = ?";
                    $userParams[] = $data['contact_number'];
                }

                if (!empty($userFields)) {
                    $userParams[] = $userId;
                    $userSql = "UPDATE tbl_users SET " . implode(', ', $userFields) . " WHERE user_id = ?";
                    $userStmt = $this->conn->prepare($userSql);
                    $userStmt->execute($userParams);
                }
            }

            // Update organizer data
            $organizerFields = [];
            $organizerParams = [];

            if (isset($data['experience_summary'])) {
                $organizerFields[] = "organizer_experience = ?";
                $organizerParams[] = $data['experience_summary'];
            }
            if (isset($data['certifications'])) {
                $organizerFields[] = "organizer_certifications = ?";
                $organizerParams[] = $data['certifications'];
            }
            if (isset($data['portfolio_link'])) {
                $organizerFields[] = "organizer_portfolio_link = ?";
                $organizerParams[] = $data['portfolio_link'];
            }
            if (isset($data['availability'])) {
                $organizerFields[] = "organizer_availability = ?";
                $organizerParams[] = $data['availability'];
            }
            if (isset($data['remarks'])) {
                $organizerFields[] = "remarks = ?";
                $organizerParams[] = $data['remarks'];
            }

            // Handle resume upload
            if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
                $resumePath = $this->uploadOrganizerFile($_FILES['resume'], 'resume');
                $organizerFields[] = "organizer_resume_path = ?";
                $organizerParams[] = $resumePath;
            }

            if (!empty($organizerFields)) {
                $organizerParams[] = $organizerId;
                $organizerSql = "UPDATE tbl_organizer SET " . implode(', ', $organizerFields) . " WHERE organizer_id = ?";
                $organizerStmt = $this->conn->prepare($organizerSql);
                $organizerStmt->execute($organizerParams);
            }

            // Log activity
            $this->logOrganizerActivity($organizerId, 'updated', 'Organizer profile updated', $userId);

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Organizer updated successfully"
            ]);

        } catch (Exception $e) {
            $this->conn->rollBack();
            return json_encode(["status" => "error", "message" => "Error updating organizer: " . $e->getMessage()]);
        }
    }

    public function deleteOrganizer($organizerId) {
        try {
            $this->conn->beginTransaction();

            // Get organizer details
            $stmt = $this->conn->prepare("SELECT user_id FROM tbl_organizer WHERE organizer_id = ?");
            $stmt->execute([$organizerId]);
            $organizer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$organizer) {
                throw new Exception("Organizer not found");
            }

            // Soft delete - update status instead of actual deletion
            $updateUserStmt = $this->conn->prepare("UPDATE tbl_users SET account_status = 'inactive' WHERE user_id = ?");
            $updateUserStmt->execute([$organizer['user_id']]);

            // Log activity
            $this->logOrganizerActivity($organizerId, 'deactivated', 'Organizer account deactivated', $organizer['user_id']);

            $this->conn->commit();

            return json_encode([
                "status" => "success",
                "message" => "Organizer deactivated successfully"
            ]);

        } catch (Exception $e) {
            $this->conn->rollBack();
            return json_encode(["status" => "error", "message" => "Error deactivating organizer: " . $e->getMessage()]);
        }
    }

    private function uploadOrganizerFile($file, $fileType) {
        $uploadDir = "uploads/organizer_documents/";
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        if ($fileType === 'profile') {
            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
            $maxSize = 5 * 1024 * 1024; // 5MB
        } else {
            $allowedTypes = ['pdf', 'doc', 'docx'];
            $maxSize = 10 * 1024 * 1024; // 10MB
        }

        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($fileExtension, $allowedTypes)) {
            throw new Exception("Invalid file type for $fileType.");
        }

        if ($file['size'] > $maxSize) {
            throw new Exception("File size exceeds limit for $fileType.");
        }

        $timestamp = time();
        $fileName = $timestamp . '_' . $fileType . '.' . $fileExtension;
        $filePath = $uploadDir . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception("Failed to upload $fileType file.");
        }

        return $filePath;
    }

    private function sendOrganizerWelcomeEmail($email, $organizerName, $tempPassword, $organizerId) {
        try {
            $subject = "Welcome to Event Coordination System - Organizer Portal";
            $portalUrl = "http://localhost:3000/auth/login"; // Update with actual domain

            $htmlContent = $this->generateOrganizerWelcomeEmailTemplate($organizerName, $email, $tempPassword, $portalUrl);
            $textContent = $this->generateOrganizerWelcomeEmailText($organizerName, $email, $tempPassword, $portalUrl);

            // Use existing email sending functionality (PHPMailer)
            require_once 'vendor/phpmailer/phpmailer/src/PHPMailer.php';
            require_once 'vendor/phpmailer/phpmailer/src/SMTP.php';
            require_once 'vendor/phpmailer/phpmailer/src/Exception.php';

            $mail = new PHPMailer\PHPMailer\PHPMailer();
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com'; // Update with your SMTP settings
            $mail->SMTPAuth = true;
            $mail->Username = 'your-email@gmail.com'; // Update with your email
            $mail->Password = 'your-app-password'; // Update with your app password
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('your-email@gmail.com', 'Event Coordination System');
            $mail->addAddress($email, $organizerName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $htmlContent;
            $mail->AltBody = $textContent;

            if (!$mail->send()) {
                throw new Exception('Email sending failed: ' . $mail->ErrorInfo);
            }

            // Log email activity
            $this->logEmailActivity($email, $organizerName, 'organizer_welcome', $subject, 'sent', null, null, $organizerId);

        } catch (Exception $e) {
            // Log email failure
            $this->logEmailActivity($email, $organizerName, 'organizer_welcome', $subject, 'failed', $e->getMessage(), null, $organizerId);
            // Don't throw exception to prevent blocking organizer creation
            error_log("Failed to send welcome email: " . $e->getMessage());
        }
    }

    private function generateOrganizerWelcomeEmailTemplate($organizerName, $email, $tempPassword, $portalUrl) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Welcome to Event Coordination System</title>
        </head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                <h1 style='color: white; margin: 0; font-size: 28px;'>Welcome to Our Team!</h1>
                <p style='color: white; margin: 10px 0 0 0; font-size: 16px;'>Event Coordination System - Organizer Portal</p>
            </div>

            <div style='background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; border: 1px solid #e9ecef;'>
                <h2 style='color: #495057; margin-top: 0;'>Hello {$organizerName}!</h2>

                <p>We're excited to welcome you as an Event Organizer in our coordination system. Your account has been created and you're now ready to start managing events!</p>

                <div style='background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #667eea; margin: 20px 0;'>
                    <h3 style='color: #495057; margin-top: 0;'>Your Account Details:</h3>
                    <p><strong>Email:</strong> {$email}</p>
                    <p><strong>Temporary Password:</strong> <code style='background: #f1f3f4; padding: 4px 8px; border-radius: 4px; font-family: monospace;'>{$tempPassword}</code></p>
                </div>

                <div style='background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 20px 0;'>
                    <p style='margin: 0; color: #856404;'><strong>Important:</strong> Please change your password upon first login for security purposes.</p>
                </div>

                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$portalUrl}' style='background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>Access Organizer Portal</a>
                </div>

                <h3 style='color: #495057;'>What you can do as an Event Organizer:</h3>
                <ul style='color: #6c757d;'>
                    <li>Manage and coordinate events</li>
                    <li>Work with clients and suppliers</li>
                    <li>Track event progress and timelines</li>
                    <li>Generate reports and analytics</li>
                    <li>Collaborate with team members</li>
                </ul>

                <p>If you have any questions or need assistance, please don't hesitate to contact our support team.</p>

                <div style='text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6;'>
                    <p style='color: #6c757d; font-size: 14px; margin: 0;'>
                        Best regards,<br>
                        Event Coordination System Team
                    </p>
                </div>
            </div>
        </body>
        </html>";
    }

    private function generateOrganizerWelcomeEmailText($organizerName, $email, $tempPassword, $portalUrl) {
        return "
Welcome to Event Coordination System - Organizer Portal

Hello {$organizerName}!

We're excited to welcome you as an Event Organizer in our coordination system. Your account has been created and you're now ready to start managing events!

Your Account Details:
- Email: {$email}
- Temporary Password: {$tempPassword}

IMPORTANT: Please change your password upon first login for security purposes.

Access your organizer portal at: {$portalUrl}

What you can do as an Event Organizer:
- Manage and coordinate events
- Work with clients and suppliers
- Track event progress and timelines
- Generate reports and analytics
- Collaborate with team members

If you have any questions or need assistance, please don't hesitate to contact our support team.

Best regards,
Event Coordination System Team
        ";
    }

    private function logOrganizerActivity($organizerId, $activityType, $description, $relatedId = null, $metadata = null) {
        try {
            // Check if activity log table exists, if not create it
            $sql = "CREATE TABLE IF NOT EXISTS tbl_organizer_activity_logs (
                        log_id INT AUTO_INCREMENT PRIMARY KEY,
                        organizer_id INT NOT NULL,
                        activity_type VARCHAR(100) NOT NULL,
                        description TEXT,
                        related_id INT,
                        metadata JSON,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )";
            $this->conn->exec($sql);

            $insertSql = "INSERT INTO tbl_organizer_activity_logs (
                            organizer_id, activity_type, description, related_id, metadata, created_at
                          ) VALUES (?, ?, ?, ?, ?, NOW())";

            $stmt = $this->conn->prepare($insertSql);
            $stmt->execute([
                $organizerId,
                $activityType,
                $description,
                $relatedId,
                json_encode($metadata)
            ]);
        } catch (Exception $e) {
            // Silently fail logging to not interrupt main operations
            error_log("Failed to log organizer activity: " . $e->getMessage());
        }
    }
}

if (!$pdo) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit();
}

// Read JSON input from frontend (for POST requests)
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

// Debug logging for troubleshooting
error_log("Admin.php - Raw input: " . $rawInput);
error_log("Admin.php - JSON decode error: " . json_last_error_msg());
error_log("Admin.php - Decoded data: " . json_encode($data));
error_log("Admin.php - POST data: " . json_encode($_POST));
error_log("Admin.php - GET data: " . json_encode($_GET));

// Handle JSON parsing errors
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    error_log("Admin.php - JSON parsing failed: " . json_last_error_msg());
    echo json_encode([
        "status" => "error",
        "message" => "Invalid JSON data: " . json_last_error_msg(),
        "raw_input" => $rawInput
    ]);
    exit;
}

// Ensure $data is an array
if (!is_array($data)) {
    $data = [];
}

// Check if operation is provided via GET or POST
$operation = $_POST['operation'] ?? ($_GET['operation'] ?? ($data['operation'] ?? ''));

error_log("Admin.php - Operation: " . $operation);
error_log("Admin.php - Final data: " . json_encode($data));

// error_log("Admin.php - Operation: " . $operation);
// error_log("Admin.php - All data: " . json_encode($data));
// error_log("Admin.php - POST: " . json_encode($_POST));
// error_log("Admin.php - GET: " . json_encode($_GET));

$admin = new Admin($pdo);

// Handle API actions
switch ($operation) {
    case "createEvent":
        error_log("Admin.php - Starting createEvent operation");
        echo $admin->createEvent($data);
        break;
    case "getAllVendors":
        echo $admin->getAllVendors();
        break;
    case "createPackage":
        echo $admin->createPackage($data);
        break;
    case "getAllPackages":
        echo $admin->getAllPackages();
        break;
    case "getPackageById":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->getPackageById($packageId);
        break;
    case "getPackageDetails":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->getPackageDetails($packageId);
        break;
    case "updatePackage":
        echo $admin->updatePackage($data);
        break;
    case "deletePackage":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->deletePackage($packageId);
        break;
    case "getEventTypes":
        echo $admin->getEventTypes();
        break;
    case "getPackagesByEventType":
        $eventTypeId = $_GET['event_type_id'] ?? ($data['event_type_id'] ?? 0);
        echo $admin->getPackagesByEventType($eventTypeId);
        break;
    case "getClients":
        echo $admin->getClients();
        break;
    case "getAllEvents":
        echo $admin->getAllEvents();
        break;
    case "getEvents":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        echo $admin->getEvents($adminId);
        break;
    case "getClientEvents":
        $userId = $_GET['user_id'] ?? ($data['user_id'] ?? 0);
        echo $admin->getClientEvents($userId);
        break;
    case "checkEventConflicts":
        $eventDate = $_GET['event_date'] ?? ($data['event_date'] ?? '');
        $startTime = $_GET['start_time'] ?? ($data['start_time'] ?? '');
        $endTime = $_GET['end_time'] ?? ($data['end_time'] ?? '');
        $excludeEventId = $_GET['exclude_event_id'] ?? ($data['exclude_event_id'] ?? null);
        echo $admin->checkEventConflicts($eventDate, $startTime, $endTime, $excludeEventId);
        break;
    case "getCalendarConflictData":
        $startDate = $_GET['start_date'] ?? ($data['start_date'] ?? '');
        $endDate = $_GET['end_date'] ?? ($data['end_date'] ?? '');
        echo $admin->getCalendarConflictData($startDate, $endDate);
        break;
    case "getEventById":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getEventById($eventId);
        break;
    case "getEnhancedEventDetails":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getEnhancedEventDetails($eventId);
        break;
    case "getBookingByReference":
        $reference = $_GET['reference'] ?? ($data['reference'] ?? '');
        echo $admin->getBookingByReference($reference);
        break;
    case "updateBookingStatus":
        $bookingId = $_GET['booking_id'] ?? ($data['booking_id'] ?? 0);
        $status = $_GET['status'] ?? ($data['status'] ?? '');
        echo $admin->updateBookingStatus($bookingId, $status);
        break;
    case "confirmBooking":
        $bookingReference = $_GET['booking_reference'] ?? ($data['booking_reference'] ?? '');
        echo $admin->confirmBooking($bookingReference);
        break;
    case "getAllBookings":
        echo $admin->getAllBookings();
        break;
    case "getAvailableBookings":
        echo $admin->getAvailableBookings();
        break;
    case "getConfirmedBookings":
        echo $admin->getConfirmedBookings();
        break;
    case "searchBookings":
        $search = $_GET['search'] ?? ($data['search'] ?? '');
        echo $admin->searchBookings($search);
        break;
    case "getEventByBookingReference":
        $bookingReference = $_GET['booking_reference'] ?? ($data['booking_reference'] ?? '');
        echo $admin->getEventByBookingReference($bookingReference);
        break;
    case "testBookingsTable":
        echo $admin->testBookingsTable();
        break;
    case "createVenue":
        echo $admin->createVenue();
        break;
    case "setVenuePaxRate":
        $venueId = $_GET['venue_id'] ?? ($data['venue_id'] ?? 0);
        $paxRate = $_GET['pax_rate'] ?? ($data['pax_rate'] ?? 0);
        echo $admin->setVenuePaxRate($venueId, $paxRate);
        break;
    case "checkAndFixVenuePaxRates":
        echo $admin->checkAndFixVenuePaxRates();
        break;
    case "testVenueData":
        echo $admin->testVenueData();
        break;
    case "getAllVenues":
        echo $admin->getAllVenues();
        break;
    case "getVenueById":
        $venueId = $_GET['venue_id'] ?? ($data['venue_id'] ?? 0);
        echo $admin->getVenueById($venueId);
        break;
    case "updateVenue":
        echo $admin->updateVenue($data);
        break;
    case "getVenuesForPackage":
        echo $admin->getVenuesForPackage();
        break;
    case "getAllAvailableVenues":
        echo $admin->getAllAvailableVenues();
        break;
    case "calculateVenuePricing":
        $venueId = $_GET['venue_id'] ?? ($data['venue_id'] ?? 0);
        $guestCount = $_GET['guest_count'] ?? ($data['guest_count'] ?? 100);
        echo $admin->calculateVenuePricing($venueId, $guestCount);
        break;
    case "createPackageWithVenues":
        echo $admin->createPackageWithVenues($data);
        break;
    case "getPackageBudgetBreakdown":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->getPackageBudgetBreakdown($packageId);
        break;
    case "validatePackageBudget":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        $components = $_GET['components'] ?? ($data['components'] ?? []);
        echo $admin->validatePackageBudget($packageId, $components);
        break;
    case "getDashboardMetrics":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        echo $admin->getDashboardMetrics($adminId);
        break;
    case "getUpcomingEvents":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        $limit = $_GET['limit'] ?? ($data['limit'] ?? 5);
        echo $admin->getUpcomingEvents($adminId, $limit);
        break;
    case "getRecentPayments":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        $limit = $_GET['limit'] ?? ($data['limit'] ?? 5);
        echo $admin->getRecentPayments($adminId, $limit);
        break;
    case "createPayment":
        echo $admin->createPayment($data);
        break;
    case "getEventPayments":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getEventPayments($eventId);
        break;
    case "getClientPayments":
        $clientId = $_GET['client_id'] ?? ($data['client_id'] ?? 0);
        echo $admin->getClientPayments($clientId);
        break;
    case "getAdminPayments":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        echo $admin->getAdminPayments($adminId);
        break;
    case "updatePaymentStatus":
        $paymentId = $_GET['payment_id'] ?? ($data['payment_id'] ?? 0);
        $status = $_GET['status'] ?? ($data['status'] ?? '');
        $notes = $_GET['notes'] ?? ($data['notes'] ?? null);
        echo $admin->updatePaymentStatus($paymentId, $status, $notes);
        break;
    case "getPaymentSchedule":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getPaymentSchedule($eventId);
        break;
    case "getEventsWithPaymentStatus":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        echo $admin->getEventsWithPaymentStatus($adminId);
        break;
    case "getPaymentAnalytics":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        $startDate = $_GET['start_date'] ?? ($data['start_date'] ?? null);
        $endDate = $_GET['end_date'] ?? ($data['end_date'] ?? null);
        echo $admin->getPaymentAnalytics($adminId, $startDate, $endDate);
        break;
    case "createPaymentSchedule":
        echo $admin->createPaymentSchedule($data);
        break;
    case "getEventPaymentSchedule":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getEventPaymentSchedule($eventId);
        break;
    case "getPaymentScheduleTypes":
        echo $admin->getPaymentScheduleTypes();
        break;
    case "recordScheduledPayment":
        echo $admin->recordScheduledPayment($data);
        break;
    case "getPaymentLogs":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getPaymentLogs($eventId);
        break;
    case "getAdminPaymentLogs":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        $limit = $_GET['limit'] ?? ($data['limit'] ?? 50);
        echo $admin->getAdminPaymentLogs($adminId, $limit);
        break;
    case "getEnhancedPaymentDashboard":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        echo $admin->getEnhancedPaymentDashboard($adminId);
        break;
    case "getAnalyticsData":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        $startDate = $_GET['start_date'] ?? ($data['start_date'] ?? null);
        $endDate = $_GET['end_date'] ?? ($data['end_date'] ?? null);
        echo $admin->getAnalyticsData($adminId, $startDate, $endDate);
        break;
    case "getReportsData":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        $reportType = $_GET['report_type'] ?? ($data['report_type'] ?? 'summary');
        $startDate = $_GET['start_date'] ?? ($data['start_date'] ?? null);
        $endDate = $_GET['end_date'] ?? ($data['end_date'] ?? null);
        echo $admin->getReportsData($adminId, $reportType, $startDate, $endDate);
        break;
    case "getUserProfile":
        $userId = $_GET['user_id'] ?? ($data['user_id'] ?? 0);
        echo $admin->getUserProfile($userId);
        break;
    case "updateUserProfile":
        echo $admin->updateUserProfile($data);
        break;
    case "changePassword":
        echo $admin->changePassword($data);
        break;
    case "getWebsiteSettings":
        echo $admin->getWebsiteSettings();
        break;
    case "updateWebsiteSettings":
        echo $admin->updateWebsiteSettings($data['settings']);
        break;
    case "getAllFeedbacks":
        echo $admin->getAllFeedbacks();
        break;
    case "deleteFeedback":
        $feedbackId = $_GET['feedback_id'] ?? ($data['feedback_id'] ?? 0);
        echo $admin->deleteFeedback($feedbackId);
        break;
    case "uploadFile":
        if (isset($_FILES['file'])) {
            $fileType = $_POST['fileType'] ?? 'misc';
            echo $admin->uploadFile($_FILES['file'], $fileType);
        } else {
            echo json_encode(["status" => "error", "message" => "No file uploaded"]);
        }
        break;
    case "uploadProfilePicture":
        if (isset($_FILES['file'])) {
            $userId = $_POST['user_id'] ?? 0;
            echo $admin->uploadProfilePicture($_FILES['file'], $userId);
        } else {
            echo json_encode(["status" => "error", "message" => "No file uploaded"]);
        }
        break;
    case "saveWeddingDetails":
        echo $admin->saveWeddingDetails($data);
        break;
    case "getWeddingDetails":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getWeddingDetails($eventId);
        break;
    case "runWeddingMigration":
        echo $admin->runWeddingMigration();
        break;
    case "uploadPaymentProof":
        if (isset($_FILES['file'])) {
            $eventId = $_POST['event_id'] ?? ($data['event_id'] ?? 0);
            $description = $_POST['description'] ?? ($data['description'] ?? '');
            $proofType = $_POST['proof_type'] ?? ($data['proof_type'] ?? 'receipt');
            echo $admin->uploadPaymentProof($eventId, $_FILES['file'], $description, $proofType);
        } else {
            echo json_encode(["status" => "error", "message" => "No file uploaded"]);
        }
        break;
    case "getPaymentProofs":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getPaymentProofs($eventId);
        break;
    case "attachPaymentProof":
        if (isset($_FILES['file'])) {
            $paymentId = $_POST['payment_id'] ?? ($data['payment_id'] ?? 0);
            $description = $_POST['description'] ?? ($data['description'] ?? '');
            $proofType = $_POST['proof_type'] ?? ($data['proof_type'] ?? 'receipt');
            echo $admin->attachPaymentProof($paymentId, $_FILES['file'], $description, $proofType);
        } else {
            echo json_encode(["status" => "error", "message" => "No file uploaded"]);
        }
        break;
    case "deletePaymentProof":
        $paymentId = $_POST['payment_id'] ?? ($data['payment_id'] ?? 0);
        $fileName = $_POST['file_name'] ?? ($data['file_name'] ?? '');
        echo $admin->deletePaymentProof($paymentId, $fileName);
        break;
    case "getEventsForPayments":
        $adminId = $_GET['admin_id'] ?? ($data['admin_id'] ?? 0);
        $searchTerm = $_GET['search_term'] ?? ($data['search_term'] ?? '');
        echo $admin->getEventsForPayments($adminId, $searchTerm);
        break;
    case "getEventPaymentDetails":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        echo $admin->getEventPaymentDetails($eventId);
        break;
    case "uploadPaymentAttachment":
        if (isset($_FILES['file'])) {
            $eventId = $_POST['event_id'] ?? ($data['event_id'] ?? 0);
            $paymentId = $_POST['payment_id'] ?? ($data['payment_id'] ?? 0);
            $description = $_POST['description'] ?? ($data['description'] ?? '');
            echo $admin->uploadPaymentAttachment($eventId, $paymentId, $_FILES['file'], $description);
        } else {
            echo json_encode(["status" => "error", "message" => "No file uploaded"]);
        }
        break;
    case "addEventComponent":
        echo $admin->addEventComponent($data);
        break;
    case "updateEventComponent":
        echo $admin->updateEventComponent($data);
        break;
    case "deleteEventComponent":
        $componentId = $_GET['component_id'] ?? ($data['component_id'] ?? 0);
        echo $admin->deleteEventComponent($componentId);
        break;
    case "updateEventBudget":
        error_log("Admin.php - updateEventBudget case reached");
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        $budgetChange = $_GET['budget_change'] ?? ($data['budget_change'] ?? 0);
        error_log("Admin.php - updateEventBudget params: eventId=$eventId, budgetChange=$budgetChange");
        echo $admin->updateEventBudget($eventId, $budgetChange);
        break;
    case "getPackageVenues":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->getPackageVenues($packageId);
        break;
    case "updateEventVenue":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        $venueId = $_GET['venue_id'] ?? ($data['venue_id'] ?? 0);
        echo $admin->updateEventVenue($eventId, $venueId);
        break;
    case "updateEventFinalization":
        $eventId = $_GET['event_id'] ?? ($data['event_id'] ?? 0);
        $action = $_GET['action'] ?? ($data['action'] ?? 'lock');
        echo $admin->updateEventFinalization($eventId, $action);
        break;
    case "createPackageWithVenues":
        echo $admin->createPackageWithVenues($data);
        break;
    case "createPackage":
        echo $admin->createPackage($data);
        break;
    case "getAllPackages":
        echo $admin->getAllPackages();
        break;
    case "getPackageById":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->getPackageById($packageId);
        break;
    case "getPackageDetails":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->getPackageDetails($packageId);
        break;
    case "updatePackage":
        echo $admin->updatePackage($data);
        break;
    case "deletePackage":
        $packageId = $_GET['package_id'] ?? ($data['package_id'] ?? 0);
        echo $admin->deletePackage($packageId);
        break;
    case "getPackagesByEventType":
        $eventTypeId = $_GET['event_type_id'] ?? ($data['event_type_id'] ?? 0);
        echo $admin->getPackagesByEventType($eventTypeId);
        break;

        // Supplier management operations
    case "createSupplier":
        // Handle FormData for file uploads
        $supplierData = $_POST;

        // Convert string booleans to actual booleans
        if (isset($supplierData['agreement_signed'])) {
            $supplierData['agreement_signed'] = ($supplierData['agreement_signed'] === 'true' || $supplierData['agreement_signed'] === '1');
        }
        if (isset($supplierData['is_verified'])) {
            $supplierData['is_verified'] = ($supplierData['is_verified'] === 'true' || $supplierData['is_verified'] === '1');
        }
        if (isset($supplierData['send_email'])) {
            $supplierData['send_email'] = ($supplierData['send_email'] === 'true' || $supplierData['send_email'] === '1');
        }
        if (isset($supplierData['create_user_account'])) {
            $supplierData['create_user_account'] = ($supplierData['create_user_account'] === 'true' || $supplierData['create_user_account'] === '1');
        }

        // Handle file uploads - process files if they exist
        if (!empty($_FILES)) {
            $documents = [];
            foreach ($_FILES as $fieldName => $file) {
                if ($file['error'] === UPLOAD_ERR_OK) {
                    // Extract document type from field name (e.g., documents[dti] -> dti)
                    if (preg_match('/documents\[(.+)\]/', $fieldName, $matches)) {
                        $documentType = $matches[1];

                        // Create upload directory if it doesn't exist
                        $uploadDir = 'uploads/supplier_documents/' . $documentType . '/';
                        if (!file_exists($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }

                        // Generate unique filename
                        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
                        $filePath = $uploadDir . $fileName;

                        // Move uploaded file
                        if (move_uploaded_file($file['tmp_name'], $filePath)) {
                            $documents[] = [
                                'document_type' => $documentType,
                                'document_title' => $file['name'],
                                'file_name' => $fileName,
                                'file_path' => $filePath,
                                'file_size' => $file['size'],
                                'file_type' => $file['type']
                            ];
                        }
                    }
                }
            }

            if (!empty($documents)) {
                $supplierData['documents'] = $documents;
            }
        }

        echo $admin->createSupplier($supplierData);
        break;
    case "getAllSuppliers":
        $page = (int)($_GET['page'] ?? 1);
        $limit = (int)($_GET['limit'] ?? 20);
        $filters = [
            'supplier_type' => $_GET['supplier_type'] ?? '',
            'specialty_category' => $_GET['specialty_category'] ?? '',
            'is_verified' => $_GET['is_verified'] ?? '',
            'onboarding_status' => $_GET['onboarding_status'] ?? '',
            'search' => $_GET['search'] ?? ''
        ];
        echo $admin->getAllSuppliers($page, $limit, $filters);
        break;
    case "getSuppliersForEventBuilder":
        $page = (int)($_GET['page'] ?? ($data['page'] ?? 1));
        $limit = (int)($_GET['limit'] ?? ($data['limit'] ?? 100));
        $filters = [
            'specialty_category' => $_GET['specialty_category'] ?? ($data['specialty_category'] ?? ''),
            'search' => $_GET['search'] ?? ($data['search'] ?? '')
        ];
        echo $admin->getSuppliersForEventBuilder($page, $limit, $filters);
        break;
    case "getSupplierById":
        $supplierId = (int)($_GET['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            echo json_encode(["status" => "error", "message" => "Valid supplier ID required"]);
        } else {
            echo $admin->getSupplierById($supplierId);
        }
        break;
    case "updateSupplier":
        $supplierId = (int)($_GET['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            echo json_encode(["status" => "error", "message" => "Valid supplier ID required"]);
        } else {
            // Handle FormData for file uploads
            $supplierData = $_POST;

            // Convert string booleans to actual booleans
            if (isset($supplierData['agreement_signed'])) {
                $supplierData['agreement_signed'] = ($supplierData['agreement_signed'] === 'true' || $supplierData['agreement_signed'] === '1');
            }
            if (isset($supplierData['is_verified'])) {
                $supplierData['is_verified'] = ($supplierData['is_verified'] === 'true' || $supplierData['is_verified'] === '1');
            }

            echo $admin->updateSupplier($supplierId, $supplierData);
        }
        break;
    case "deleteSupplier":
        $supplierId = (int)($_GET['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            echo json_encode(["status" => "error", "message" => "Valid supplier ID required"]);
        } else {
            echo $admin->deleteSupplier($supplierId);
        }
        break;
    case "getSupplierCategories":
        echo $admin->getSupplierCategories();
        break;
    case "getSupplierStats":
        echo $admin->getSupplierStats();
        break;
    case "getSupplierDocuments":
        $supplierId = (int)($_GET['supplier_id'] ?? 0);
        $documentType = $_GET['document_type'] ?? null;
        if ($supplierId <= 0) {
            echo json_encode(["status" => "error", "message" => "Valid supplier ID required"]);
        } else {
            echo $admin->getSupplierDocuments($supplierId, $documentType);
        }
        break;
    case "getDocumentTypes":
        echo $admin->getDocumentTypes();
        break;

    // Organizer Management
    case "createOrganizer":
        echo $admin->createOrganizer($data);
        break;
    case "getAllOrganizers":
        $page = (int)($_GET['page'] ?? 1);
        $limit = (int)($_GET['limit'] ?? 20);
        $filters = [
            'search' => $_GET['search'] ?? '',
            'availability' => $_GET['availability'] ?? '',
            'is_active' => $_GET['is_active'] ?? ''
        ];
        echo $admin->getAllOrganizers($page, $limit, $filters);
        break;
    case "getOrganizerById":
        $organizerId = (int)($_GET['organizer_id'] ?? 0);
        if ($organizerId <= 0) {
            echo json_encode(["status" => "error", "message" => "Valid organizer ID required"]);
        } else {
            echo $admin->getOrganizerById($organizerId);
        }
        break;
    case "updateOrganizer":
        $organizerId = (int)($_GET['organizer_id'] ?? ($data['organizer_id'] ?? 0));
        if ($organizerId <= 0) {
            echo json_encode(["status" => "error", "message" => "Valid organizer ID required"]);
        } else {
            echo $admin->updateOrganizer($organizerId, $data);
        }
        break;
    case "deleteOrganizer":
        $organizerId = (int)($_GET['organizer_id'] ?? 0);
        if ($organizerId <= 0) {
            echo json_encode(["status" => "error", "message" => "Valid organizer ID required"]);
        } else {
            echo $admin->deleteOrganizer($organizerId);
        }
        break;

    // Customized Package operations
    case "createCustomizedPackage":
        echo $admin->createCustomizedPackage($data);
        break;

    // File upload operations
    case "upload":
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(["status" => "error", "message" => "No file uploaded or upload error"]);
        } else {
            $fileType = $_POST['type'] ?? 'misc';
            echo $admin->uploadFile($_FILES['file'], $fileType);
        }
        break;

    default:
        error_log("Admin.php - Unknown operation: " . $operation);
        error_log("Admin.php - Available data: " . json_encode($data));
        echo json_encode([
            "status" => "error",
            "message" => "Invalid or missing operation: '$operation'",
            "received_data" => $data
        ]);
        break;
}


?>
