<?php
class Database
{
    private $mysqli;
    private static $instance;

    public function __construct()
    {
        $config = require('config.php'); // Adjust the path if necessary
        $this->mysqli = new mysqli(
            $config['HOST'],
            $config['USERNAME'],
            $config['PASSWORD'],
            $config['DB_NAME'],
            // $config['PORT']
        );

        // Check for connection error
        if ($this->mysqli->connect_error) {
            die("Connection failed: " . $this->mysqli->connect_error);
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    // todo: change the return type to userID, or 0 if wala
    public function login($username, $password)
    {
        $stmt = $this->mysqli->prepare("SELECT v.user_id, u.password 
                                    FROM vendors AS v 
                                    JOIN users AS u ON u.user_id = v.user_id 
                                    WHERE u.username = ?");

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
    
        //Check if user exists
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $storedPassword = $row['password'];
            $storedUserId = $row['user_id'];
            if ($password === $storedPassword) {
                return $storedUserId; //Correct password
            } else {
                return 0; //Incorrect password
            }
        } else { //User does not exist/not a vendor
            return 0;
        }
    }

    //todo: create a function that fetches organization data based on the given userID
    public function getOrganizationData($userID){
        // make use of the Organization class, pero i null mo lang other fields. ang important lang is OrgID, OrgName, Org Logo, then return mo object na Organization
        $stmt = $this->mysqli->prepare("SELECT o.organization_id, o.organization_name, o.organization_description, o.logo FROM organizations AS o
                                        JOIN vendors AS v USING (organization_id) WHERE v.user_id = ?");
        
        $stmt->bind_param('i', $userID);
        $stmt->execute();
        $result = $stmt->get_result();
        // Check if an organization was found
        if ($row = $result->fetch_assoc()) {
            
            return new Organization(
                $row['organization_id'],
                $row['organization_name'],
                $row['organization_description'],
                $row['logo'],
                null
            );
        } else {
            // If no organization is found, return null
            return null;
        }
    }

    //This method fetches all orders that are pending
    //todo: Create a function that fetches the number of pending orders of the organization using the orgID
    public function getTotalPendingOrders($organizationID) {
        // kinukuha lng ung total pending orders
        $stmt = $this->mysqli->prepare( "SELECT COUNT(DISTINCT(o.order_id)) AS total_pending 
                                    FROM orders AS o 
                                    JOIN order_products AS op USING (order_id)
                                    JOIN products AS p USING (product_id)
                                    WHERE p.organization_id =?
                                    AND o.status = 'pending'");
        $stmt->bind_param('i', $organizationID);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return (int)$row['total_pending'];
    }

    //todo: Create a function that fetches the sales data of the organization using the orgID
    public function getSales($organizationID) {
        // kinukuha lng ung total sales ng org
            $stmt = $this->mysqli->prepare( "SELECT SUM(o.total) AS total_sales FROM orders AS o where o.order_id IN(
            Select DISTINCT(o.order_id) FROM orders AS o JOIN order_products AS op USING(order_id) 
            JOIN products AS p USING (product_id) 
            WHERE p.organization_id = ?
            AND o.status = 'claimed')");

            $stmt->bind_param('i', $organizationID);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            return (int)$row['total_sales'];    
        
            // ERROR IN THE SQL SYNTAX
    }

    public function getMostOrderedProducts($organizationID, $limit) {
        $stmt = $this->mysqli->prepare("SELECT COUNT(op.product_id) AS order_count, p.product_name, 
        MAX(p.product_image) AS product_image 
            FROM orders AS o 
            JOIN order_products AS op USING (order_id)
            JOIN products AS p USING (product_id)
            JOIN organizations AS org USING (organization_id)
            WHERE o.status = 'claimed' 
            AND org.organization_id = ? 
            GROUP BY p.product_name 
            ORDER BY order_count DESC
            LIMIT ?");

        $stmt->bind_param('ii', $organizationID, $limit);
        $stmt->execute();
        

        $result = $stmt->get_result();
        $mostOrderedProducts = [];

        while ($row = $result->fetch_assoc()) {
            // $mostOrderedProducts[] = [
            //     'product_name' => $row['product_name'],
            //     'order_count' => (int)$row['order_count']
            // ];
            $mostOrderedProducts[] = $row;
        }

        // Return the array of most ordered products
        return $mostOrderedProducts;
    }


    public function getClaimedOrders($organizationID){
        $stmt = $this->mysqli->prepare("SELECT DISTINCT u.user_id, u.first_name, u.last_name, o.total AS order_total, o.status, o.order_id, o.created_at, o.claimed_at, os.location 
                                        FROM organization_schedules AS os
                                        JOIN orders AS o USING (schedule_id)
                                        JOIN order_products AS op USING (order_id) 
                                        JOIN products AS p USING (product_id) 
                                        JOIN users AS u ON o.customer_id = u.user_id 
                                        WHERE p.organization_id = ?
                                        AND (o.status = 'Claimed' OR o.status = 'Cancelled')");
        $stmt->bind_param('i', $organizationID);
        $stmt->execute();
        $result = $stmt->get_result();
        $pendingProducts = [];
        while ($row = $result->fetch_assoc()) {
            $pendingProducts[] = $row;
        }
        $stmt->close();
        return $pendingProducts;
        
    }
    //TODO: create a function that updates the status of an order from pending to claimed
    public function updateProductStatus($orderID){
        $stmt = $this->mysqli->prepare("UPDATE orders SET status = 'Claimed', claimed_at = NOW() WHERE order_id =?; ");
        $stmt->bind_param('i', $orderID);
        
        $stmt->execute();
            
        if ($stmt->affected_rows > 0) {
            return true;
        } else {
            return false;
        }
        
    }
   
    // todo: create a function that adds a product to the database. 
    public function addProduct($product){
        // this is product $product = new Product(null, $productName, $productDescription, $organizationID, $productPrice, $productQuantity, $productImage, $status );
        // null yung product ID kase auto increment na yan sa database
        // if successful return  true, else false
        $stmt = $this->mysqli->prepare("INSERT INTO products(product_name, product_description, organization_id, price, quantity, product_image, status)
                                        VALUES (?,?,?,?,?,?,?)");
     
        $productName = $product->getProductName(); 
        $productDescription = $product->getProductDescription();
        $productOrganizationID = $product->getOrganizationID();
        $productPrice = $product->getPrice();
        $productQuantity = $product->getQuantity();
        $productImage = $product->getProductImage();
        $productStatus = $product->getStatus();

        $stmt->bind_param('ssidibs', 
        $productName, 
        $productDescription,
        $productOrganizationID,
        $productPrice,
        $productQuantity,       
        $nullBlob,
        $productStatus);

         // Send binary data (BLOB) in chunks
        $stmt->send_long_data(5, $productImage);
        
        if ($stmt->execute()) {
            return true; 
        } else {
            return false;
        }
    }
    public function addSchedule($schedule){
        $stmt = $this->mysqli->prepare("INSERT INTO organization_schedules(date, organization_id, start_time, end_time, location)
        VALUES (?,?,?,?,?)");

        $date = $schedule-> getDate();
        $organizationID = $schedule-> getOrganizationID();
        $startTime = $schedule->getStartTime();
        $endTime = $schedule->getEndTime();
        $location = $schedule->getLocation();

        $stmt->bind_param("sisss", $date, $organizationID,$startTime, $endTime, $location);
        if ($stmt->execute()) {
            return true; 
        } else {
            return false;
        }
    }
    public function getSchedule($organizationID){
        $stmt = $this->mysqli->prepare("Select schedule_id, date, start_time, end_time, location, status 
                                                From organization_schedules WHERE organization_id = ?");
        $stmt->bind_param('i', $organizationID);
        $stmt->execute();
        $result = $stmt->get_result();

        $schedules = [];

        // Fetch each row and instantiate Product objects
        while ($row = $result->fetch_assoc()) {
            $schedules[] = $row;
        }

        // Return the array of Product objects
        return $schedules;   
    }
    public function editSchedule($scheduleID,$date, $startTime, $endTime, $location){
        $stmt = $this->mysqli->prepare("UPDATE organization_schedules SET date = ?, 
                                                start_time =?, end_time = ?, location =? WHERE schedule_id =?;");
        $stmt->bind_param('ssssi', $date, $startTime, $endTime, $location, $scheduleID);
        if ($stmt->execute()) {
            return true; 
        } else {
            return false;
        }
    }

    public function deleteSchedule($scheduleID) {
        $stmt = $this->mysqli->prepare("UPDATE organization_schedules SET status = 'cancelled' WHERE schedule_id = ?;");

        $stmt->bind_param('i', $scheduleID);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $stmt->close();
                return true;
            }
        }

        $stmt->close();

        return false;
    }

    // method that returns  the products information
    // needed: Product ID, Product Name, qty, price, status
    // other fields must be null to save data 
    // use the product class
    public function getAllProducts($organizationID){
        $stmt = $this->mysqli->prepare("SELECT product_id, product_name, quantity, price, status FROM `products` WHERE organization_id = ? AND status NOT LIKE 'deleted';");
        $stmt->bind_param('i', $organizationID); 
        $stmt->execute();
        $result = $stmt->get_result();

        $allProducts = [];

        // Fetch each row and instantiate Product objects
        while ($row = $result->fetch_assoc()) {
            // Instantiate the Product object, passing null for fields not in the result
            $product = new Product(
                $row['product_id'],              // productID
                $row['product_name'],            // productName
                null,                            // productDescription (set to null)
                $organizationID,                 // organizationID (you have this available)
                $row['price'],                   // price
                $row['quantity'],                // quantity
                null,                            // productImage (set to null)
                $row['status']                   // status
            );
            
            $allProducts[] = $product;
        }

        // Return the array of Product objects
        return $allProducts;   
    }

    public function getPendingOrders($organizationID) {
        $stmt = $this->mysqli->prepare("SELECT DISTINCT u.user_id, u.first_name, u.last_name, o.total AS order_total, o.status, o.order_id, o.created_at, o.claimed_at, os.location, os.date
                                        FROM organization_schedules AS os
                                        JOIN orders AS o USING (schedule_id)
                                        JOIN order_products AS op USING (order_id) 
                                        JOIN products AS p USING (product_id) 
                                        JOIN users AS u ON o.customer_id = u.user_id 
                                        WHERE p.organization_id = ? AND o.status = 'Pending'");
    
        $stmt->bind_param('i', $organizationID);
        $stmt->execute();
        $result = $stmt->get_result();
        $pendingOrders = [];
    
        // Get the current date
        $currentDate = date('Y-m-d'); // Format to match os.date
    
        // Fetch each row and check the date
        while ($row = $result->fetch_assoc()) {
            // Check if the current date is greater than os.date
            if ($currentDate > $row['date']) {
                // Update the order status to 'Cancelled'
                $updateStmt = $this->mysqli->prepare("UPDATE orders SET status = 'Cancelled' WHERE order_id = ?");
                $updateStmt->bind_param('i', $row['order_id']);
                $updateStmt->execute();
                $updateStmt->close();
    
                // Update the product quantities in the products table
                // Fetch the products related to the order
                $productStmt = $this->mysqli->prepare("SELECT op.product_id, op.quantity FROM order_products AS op WHERE op.order_id = ?");
                $productStmt->bind_param('i', $row['order_id']);
                $productStmt->execute();
                $productResult = $productStmt->get_result();
    
                // Loop through each product and update the quantity in the products table
                while ($productRow = $productResult->fetch_assoc()) {
                    $productUpdateStmt = $this->mysqli->prepare("UPDATE products SET quantity = quantity + ? WHERE product_id = ?");
                    $productUpdateStmt->bind_param('ii', $productRow['quantity'], $productRow['product_id']);
                    $productUpdateStmt->execute();
                    $productUpdateStmt->close();
                }
                $productStmt->close();
            }
            // Add the order to the result array
            $pendingOrders[] = $row;
        }
    
        $stmt->close();
    
        // Return the updated pending orders
        return $pendingOrders;
    }

    public function getPendingProducts($organizationID){
        $stmt = $this->mysqli->prepare("SELECT o.order_id, u.user_id, u.first_name, u.last_name, o.status,  p.product_name, op.quantity, op.total, TO_BASE64(p.product_image) AS product_image_base64
										FROM orders AS o JOIN order_products AS op ON o.order_id = op.order_id 
                                        JOIN products AS p ON op.product_id = p.product_id 
                                        JOIN users AS u ON o.customer_id = u.user_id 
                                        WHERE p.organization_id = ?
                                        AND o.status = 'pending'");
        $stmt->bind_param('i', $organizationID);
        $stmt->execute();
        $result = $stmt->get_result();
        $pendingProducts = [];
        while ($row = $result->fetch_assoc()) {
            $pendingProducts[] = $row;
        }
        $stmt->close();
        return $pendingProducts;
    }
    
    public function getClaimedProducts($organizationID){
        $stmt = $this->mysqli->prepare("SELECT o.order_id, u.user_id, u.first_name, u.last_name, o.status,  p.product_name, op.quantity, op.total, TO_BASE64(p.product_image) AS product_image_base64
										FROM orders AS o JOIN order_products AS op ON o.order_id = op.order_id 
                                        JOIN products AS p ON op.product_id = p.product_id 
                                        JOIN users AS u ON o.customer_id = u.user_id 
                                        WHERE p.organization_id = ?
                                        AND (o.status = 'Claimed' OR o.status = 'Cancelled')");
        $stmt->bind_param('i', $organizationID);
        $stmt->execute();
        $result = $stmt->get_result();
        $claimedProducts = [];
        while ($row = $result->fetch_assoc()) {
            $claimedProducts[] = $row;
        }
        $stmt->close();
        return $claimedProducts;
    }
    //TODO: create a query that will fetch all of the pending based on the chosen filter options
    public function getPendingOrdersFiltered($organizationID, $filters = []) {
        $sql = "SELECT DISTINCT u.user_id, u.first_name, u.last_name, o.total AS order_total, o.status, o.order_id, o.created_at, o.claimed_at, os.location
                FROM organization_schedules AS os
                JOIN orders AS o USING (schedule_id)
                JOIN order_products AS op USING (order_id)
                JOIN products AS p USING (product_id)
                JOIN users AS u ON o.customer_id = u.user_id
                WHERE p.organization_id = ? AND o.status = 'pending'";
    
        $params = [$organizationID];
        $types = 'i'; 
    

        if (isset($filters[0]) && $filters[0] != "All") {
            $location = $filters[0];
            $sql .= " AND os.location LIKE ?";
            $params[] = "%$location%";
            // $params[]= "Devesse Plaza";
            // $params[]= "Amphitheatre";
            // $params[]= "4th Floor Balcony Right Wing";
            $types .= 's'; 
        }
        //todo fix date calculation
        if (isset($filters[1])) {
            $dateRange = $filters[1]; 
            if ($dateRange>1) {
            
                $dateFrom = date('Y-m-d', strtotime("-$dateRange days")) . ' 00:00:00';
                $currentDate = date('Y-m-d') . ' 23:59:59';
                // $sql .= " AND o.created_at >= ? AND o.created_at <= ?";
                $sql .= " AND o.created_at BETWEEN ? AND ?";
                $params[] = $dateFrom;
                $params[] = $currentDate;
                $types .= 'ss'; 
            } elseif ($dateRange ==1) { //removable (only used for debugging anyways) if yes, change first if statement to >=1
                $dateFrom = date('Y-m-d', strtotime('-1 day')) . ' 00:00:00';
                $dateTo = date('Y-m-d', strtotime('-1 day')) . ' 23:59:59';
                $sql .= " AND o.created_at BETWEEN ? AND ?";
                $params[] = $dateFrom;
                $params[] = $dateTo;
                $types .= 'ss'; 
            } elseif ($dateRange == 0) {
                $dateFrom = date('Y-m-d') . ' 00:00:00';
                $dateTo = date('Y-m-d') . ' 23:59:59';
                $sql .= " AND o.created_at BETWEEN ? AND ?";
                $params[] = $dateFrom;
                $params[] = $dateTo;
                $types .= 'ss'; 
            }
        }
          // Check if the second filter (date range) is set and is an integer
        // if (isset($filters[1])) {
        //     $dateRange = $filters[1];
        //     if($dateRange>=0){
        //     $sql .= " AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
        //     $params[] = $dateRange;
        //     $types .= 'i';
        //     }
        // }

        
        $stmt = $this->mysqli->prepare($sql);
        
        $stmt->bind_param($types, ...$params);
        
        $stmt->execute();
        $result = $stmt->get_result();
        $pendingOrders = [];
        
        // Fetch each row
        while ($row = $result->fetch_assoc()) {
            $pendingOrders[] = $row;
        }
        $stmt->close();
    
        
        return $pendingOrders;
    }
    public function getClaimedOrdersFiltered($organizationID, $filters = []) {
        $sql = "SELECT DISTINCT u.user_id, u.first_name, u.last_name, o.total AS order_total, o.status, o.order_id, o.created_at, o.claimed_at, os.location
                FROM organization_schedules AS os
                JOIN orders AS o USING (schedule_id)
                JOIN order_products AS op USING (order_id)
                JOIN products AS p USING (product_id)
                JOIN users AS u ON o.customer_id = u.user_id
                WHERE p.organization_id = ? AND (o.status = 'Claimed' OR o.status = 'Cancelled')";
    
        $params = [$organizationID];
        $types = 'i'; 
    

        if (isset($filters[0]) && $filters[0] != "All") {
            $location = $filters[0];
            $sql .= " AND os.location LIKE ?";
            $params[] = "%$location%";
            // $params[]= "Devesse Plaza";
            // $params[]= "Amphitheatre";
            // $params[]= "4th Floor Balcony Right Wing";
            $types .= 's'; 
        }
        //todo fix date calculation
        if (isset($filters[1])) {
            $dateRange = $filters[1]; 
            if ($dateRange>1) {
            
                $dateFrom = date('Y-m-d', strtotime("-$dateRange days")) . ' 00:00:00';
                $currentDate = date('Y-m-d') . ' 23:59:59';
                // $sql .= " AND o.created_at >= ? AND o.created_at <= ?";
                $sql .= " AND o.created_at BETWEEN ? AND ?";
                $params[] = $dateFrom;
                $params[] = $currentDate;
                $types .= 'ss'; 
            } elseif ($dateRange ==1) { //removable (only used for debugging anyways) if yes, change first if statement to >=1
                $dateFrom = date('Y-m-d', strtotime('-1 day')) . ' 00:00:00';
                $dateTo = date('Y-m-d', strtotime('-1 day')) . ' 23:59:59';
                $sql .= " AND o.created_at BETWEEN ? AND ?";
                $params[] = $dateFrom;
                $params[] = $dateTo;
                $types .= 'ss'; 
            } elseif ($dateRange == 0) {
                $dateFrom = date('Y-m-d') . ' 00:00:00';
                $dateTo = date('Y-m-d') . ' 23:59:59';
                $sql .= " AND o.created_at BETWEEN ? AND ?";
                $params[] = $dateFrom;
                $params[] = $dateTo;
                $types .= 'ss'; 
            }
        }
        
        
        $stmt = $this->mysqli->prepare($sql);
        
        $stmt->bind_param($types, ...$params);
        
        $stmt->execute();
        $result = $stmt->get_result();
        $pendingOrders = [];
        
        // Fetch each row
        while ($row = $result->fetch_assoc()) {
            $pendingOrders[] = $row;
        }
        $stmt->close();
    
        
        return $pendingOrders;
    }

    //todo: method name: deleteProduct, params: productId
    //create a query that marks the status of the product in the PRODUCTS table as deleted. do not delete the product
    public function deleteProduct($productID){
        $stmt = $this->mysqli->prepare("UPDATE products SET status = 'deleted' WHERE product_id = ?");
     
        $stmt->bind_param('i', $productID);
        
        if ($stmt->execute()) {
            return true; 
        } else {
            return false;
        }
    }
    //create a query that edits the name, price, and quantity of the product.
    public function editProduct($productID, $name, $price, $quantity){
        $stmt = $this->mysqli->prepare("UPDATE products SET product_name = ?, price =?, quantity = ? WHERE product_id =?;");
        $stmt->bind_param('sdii', $name, $price, $quantity, $productID);
        if ($stmt->execute()) {
            return true; 
        } else {
            return false;
        }
    }
}
?>
