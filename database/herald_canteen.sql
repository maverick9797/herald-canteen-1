-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 18, 2026 at 06:26 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `herald_canteen`
--

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `cart_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `item_id` int(10) UNSIGNED NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `added_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cart`
--

INSERT INTO `cart` (`cart_id`, `user_id`, `item_id`, `quantity`, `total_price`, `added_at`) VALUES
(8, 1, 38, 1, 420.00, '2026-04-18 21:44:07'),
(9, 1, 50, 1, 320.00, '2026-04-18 21:44:12');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `category_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`category_id`, `name`, `image_url`, `description`, `is_available`, `created_at`, `updated_at`) VALUES
(25, 'Burgers', '../assets/images/burger.jpg', 'Freshly made burgers with premium ingredients', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(26, 'Pizza', '../assets/images/pizza.jpg', 'Stone baked pizzas with authentic flavors', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(27, 'Momo', '../assets/images/momo.jpg', 'Traditional Nepali dumplings steamed to perfection', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(28, 'Pasta', '../assets/images/pasta.jpg', 'Creamy and rich Italian style pastas', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(29, 'Drinks', '../assets/images/drinks.jpg', 'Refreshing cold and hot beverages', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(30, 'Desserts', '../assets/images/deserts.jpg', 'Sweet treats to end your meal right', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(31, 'Sandwiches', '../assets/images/sandwich.jpg', 'Freshly made sandwiches with premium fillings', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(32, 'Rolls', '../assets/images/rolls.jpg', 'Crispy and soft rolls packed with spiced fillings', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(33, 'Rice Bowls', '../assets/images/rice.jpg', 'Hearty rice bowls with rich curries and toppings', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(34, 'Noodles', '../assets/images/noodles.jpg', 'Stir fried and soupy noodles with bold flavors', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(35, 'Snacks', '../assets/images/snacks.jpg', 'Light bites and crispy snacks to munch on', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(36, 'Soup', '../assets/images/soup.jpg', 'Warm and comforting soups for any time of day', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(37, 'Sushi', '../assets/images/sushi.jpg', 'Fresh Japanese sushi and rolls', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(38, 'Tacos', '../assets/images/tacos.jpg', 'Mexican style tacos with bold and spicy flavors', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(39, 'Steak', '../assets/images/steak.jpg', 'Grilled to perfection steaks with rich sauces', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(40, 'Salads', '../assets/images/salad.jpg', 'Fresh and healthy salads with vibrant plating', 1, '2026-04-18 20:21:01', '2026-04-18 20:52:36'),
(41, 'Waffles', '../assets/images/waffles.jpg', 'Crispy Belgian waffles with sweet and savory toppings', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(42, 'Kebabs', '../assets/images/kebabs.jpg', 'Smoky grilled kebabs with aromatic spices', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(43, 'Seafood', '../assets/images/seafood.jpg', 'Fresh catch of the day cooked to perfection', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(44, 'Shawarma', '../assets/images/shawarma.jpg', 'Slow roasted meat wrapped in flatbread', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(45, 'Sizzler', '../assets/images/sizzler.jpg', 'Hot sizzling platters on cast iron with rich sauces', 1, '2026-04-18 20:21:01', '2026-04-18 20:21:01'),
(46, 'Donuts', '../assets/images/category_1776527505_69e3a8914e520.jpg', 'Soft and tasty donuts', 1, '2026-04-18 21:36:45', '2026-04-18 21:36:45');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `attempt_id` int(10) UNSIGNED NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `menu_items`
--

CREATE TABLE `menu_items` (
  `item_id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `rating` decimal(2,1) NOT NULL DEFAULT 0.0,
  `is_available` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `menu_items`
--

INSERT INTO `menu_items` (`item_id`, `category_id`, `name`, `description`, `price`, `image_url`, `rating`, `is_available`, `created_at`, `updated_at`) VALUES
(35, 25, 'Classic Beef Burger', 'Grilled beef patty with lettuce, tomato, cheddar and signature sauce.', 350.00, NULL, 4.7, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(36, 25, 'Chicken Crispy Burger', 'Crispy fried chicken fillet with coleslaw, pickles and mayo.', 300.00, NULL, 4.5, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(37, 25, 'Double Smash Burger', 'Two smashed beef patties with double cheese and caramelized onions.', 450.00, NULL, 4.8, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(38, 25, 'BBQ Bacon Burger', 'Smoky beef patty with crispy bacon, BBQ sauce and cheddar.', 420.00, NULL, 4.6, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(39, 25, 'Veggie Burger', 'Grilled vegetable patty with avocado, fresh greens and garlic aioli.', 280.00, NULL, 4.3, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(40, 26, 'Margherita Pizza', 'Classic tomato base with fresh mozzarella and basil.', 400.00, NULL, 4.6, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(41, 26, 'Pepperoni Pizza', 'Premium pepperoni on a rich tomato and mozzarella base.', 480.00, NULL, 4.7, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(42, 26, 'BBQ Chicken Pizza', 'Grilled chicken, red onions and BBQ sauce on a crispy thin crust.', 520.00, NULL, 4.5, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(43, 26, 'Mushroom Truffle Pizza', 'Sauteed mushrooms, truffle oil and parmesan on a white cream base.', 550.00, NULL, 4.8, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(44, 26, 'Veggie Supreme Pizza', 'Bell peppers, olives, onions, mushrooms and sweet corn.', 450.00, NULL, 4.4, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(45, 27, 'Chicken Steam Momo', 'Classic steamed momo filled with minced chicken and Nepali spices.', 160.00, NULL, 4.9, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(46, 27, 'Buff Momo', 'Traditional buffalo momo with cumin, coriander and timur achar.', 150.00, NULL, 4.8, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(47, 27, 'Paneer Momo', 'Soft paneer and vegetable filling with sesame achar.', 170.00, NULL, 4.6, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(48, 27, 'Fried Momo', 'Crispy deep fried momo with spicy tomato achar.', 180.00, NULL, 4.7, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(49, 27, 'C Momo', 'Steamed momo tossed in spicy and tangy C sauce.', 190.00, NULL, 4.5, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(50, 28, 'Chicken Alfredo', 'Grilled chicken in a rich creamy parmesan sauce over fettuccine.', 320.00, NULL, 4.6, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(51, 28, 'Spaghetti Bolognese', 'Classic minced beef ragu slow cooked with tomatoes over spaghetti.', 310.00, NULL, 4.5, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(52, 28, 'Penne Arrabbiata', 'Penne in a fiery tomato and garlic sauce with chili flakes.', 270.00, NULL, 4.3, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(53, 28, 'Mushroom Carbonara', 'Creamy carbonara with sauteed mushrooms and crispy pancetta.', 340.00, NULL, 4.7, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(54, 28, 'Pesto Genovese', 'Fresh basil pesto with pine nuts and parmesan over linguine.', 330.00, NULL, 4.6, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(55, 29, 'Cold Coffee', 'Chilled espresso blended with milk, ice cream and vanilla.', 150.00, NULL, 4.6, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(56, 29, 'Fresh Lime Soda', 'Freshly squeezed lime with sparkling soda and mint.', 100.00, NULL, 4.4, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(57, 29, 'Mango Lassi', 'Thick yogurt blended with fresh Alphonso mango pulp.', 130.00, NULL, 4.5, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(58, 29, 'Masala Chai', 'Spiced tea brewed with ginger, cardamom and fresh milk.', 80.00, NULL, 4.6, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(59, 29, 'Strawberry Shake', 'Fresh strawberry milkshake with vanilla ice cream.', 170.00, NULL, 4.7, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(60, 30, 'Chocolate Lava Cake', 'Warm dark chocolate cake with a gooey molten center.', 220.00, NULL, 4.8, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(62, 30, 'Mango Cheesecake', 'Baked cheesecake with buttery biscuit base and fresh mango topping.', 250.00, NULL, 4.7, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(63, 30, 'Brownie Sundae', 'Warm chocolate brownie with vanilla ice cream and chocolate sauce.', 200.00, NULL, 4.8, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(64, 30, 'Tiramisu', 'Classic Italian coffee flavored dessert with mascarpone.', 280.00, NULL, 4.9, 1, '2026-04-18 20:29:03', '2026-04-18 20:29:03'),
(65, 31, 'Club Sandwich', 'Triple layer sandwich with chicken, bacon, lettuce, tomato and mayo.', 250.00, NULL, 4.5, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(66, 31, 'Grilled Chicken Sandwich', 'Grilled chicken breast with avocado, lettuce and honey mustard.', 230.00, NULL, 4.6, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(67, 31, 'Veg Club Sandwich', 'Grilled vegetables, cheese, lettuce and mint chutney.', 180.00, NULL, 4.3, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(68, 31, 'Paneer Tikka Sandwich', 'Spicy grilled paneer with onions, capsicum and tandoori mayo.', 210.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(69, 31, 'Egg Mayo Sandwich', 'Creamy egg mayo with black pepper and fresh lettuce.', 160.00, NULL, 4.4, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(70, 32, 'Chicken Roll', 'Soft roll filled with grilled chicken, onions, cabbage and mayo.', 180.00, NULL, 4.5, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(71, 32, 'Egg Roll', 'Scrambled egg roll with onions, capsicum and schezwan sauce.', 120.00, NULL, 4.3, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(72, 32, 'Paneer Roll', 'Grilled paneer with mint chutney and fresh veggies.', 160.00, NULL, 4.4, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(73, 32, 'Double Egg Chicken Roll', 'Double egg layered roll with spicy chicken filling.', 220.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(74, 32, 'Veg Spring Roll', 'Crispy spring roll with shredded vegetables and noodles.', 140.00, NULL, 4.2, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(75, 33, 'Chicken Biryani', 'Aromatic basmati rice cooked with spicy chicken and herbs.', 280.00, NULL, 4.8, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(76, 33, 'Veg Fried Rice', 'Stir fried rice with mixed vegetables and egg.', 180.00, NULL, 4.3, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(77, 33, 'Chicken Fried Rice', 'Wok tossed rice with egg, chicken and spring onions.', 220.00, NULL, 4.5, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(78, 33, 'Egg Biryani', 'Flavorful biryani with boiled eggs and fried onions.', 200.00, NULL, 4.4, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(79, 33, 'Mixed Fried Rice', 'Combination of chicken, egg and vegetables.', 250.00, NULL, 4.6, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(80, 34, 'Chow Mein', 'Stir fried noodles with chicken and vegetables.', 200.00, NULL, 4.5, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(81, 34, 'Hakka Noodles', 'Spicy hakka style noodles with bell peppers and carrots.', 180.00, NULL, 4.4, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(82, 34, 'Egg Noodles', 'Noodles tossed with scrambled eggs and spring onions.', 190.00, NULL, 4.3, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(83, 34, 'Veg Schezwan Noodles', 'Spicy schezwan noodles with mixed vegetables.', 170.00, NULL, 4.2, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(84, 34, 'Chicken Pad Thai', 'Thai style rice noodles with chicken, peanuts and bean sprouts.', 280.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(85, 35, 'French Fries', 'Crispy golden fries served with tomato ketchup.', 120.00, NULL, 4.5, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(86, 35, 'Chicken Popcorn', 'Bite sized crispy fried chicken pieces.', 180.00, NULL, 4.6, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(87, 35, 'Cheese Balls', 'Deep fried cheese filled balls with herbs.', 150.00, NULL, 4.4, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(88, 35, 'Chicken Wings', 'Spicy baked chicken wings with BBQ dip.', 220.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(89, 35, 'Spring Rolls', 'Crispy vegetable spring rolls with sweet chili sauce.', 140.00, NULL, 4.3, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(90, 36, 'Chicken Noodle Soup', 'Clear soup with chicken strips and egg noodles.', 160.00, NULL, 4.5, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(91, 36, 'Hot and Sour Soup', 'Spicy and tangy soup with vegetables and egg.', 140.00, NULL, 4.4, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(92, 36, 'Sweet Corn Soup', 'Creamy sweet corn soup with egg drops.', 130.00, NULL, 4.3, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(93, 36, 'Tomato Soup', 'Classic creamy tomato soup with croutons.', 120.00, NULL, 4.2, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(94, 36, 'Mushroom Soup', 'Creamy mushroom soup with garlic bread.', 150.00, NULL, 4.6, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(95, 37, 'California Roll', 'Sushi roll with crab, avocado and cucumber.', 350.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(96, 37, 'Spicy Tuna Roll', 'Tuna mixed with spicy mayo and cucumber.', 380.00, NULL, 4.8, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(97, 37, 'Salmon Nigiri', 'Fresh salmon slice over pressed rice.', 400.00, NULL, 4.9, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(98, 37, 'Dragon Roll', 'Eel and cucumber topped with avocado slices.', 450.00, NULL, 4.8, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(99, 37, 'Veg Sushi Roll', 'Cucumber, avocado and carrot with rice and nori.', 280.00, NULL, 4.4, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(100, 38, 'Chicken Tacos', 'Soft tortilla with spicy chicken, lettuce and cheese.', 280.00, NULL, 4.6, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(101, 38, 'Fish Tacos', 'Crispy fish fillet with coleslaw and lime crema.', 320.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(102, 38, 'Veg Tacos', 'Beans, corn, lettuce and avocado in soft tortilla.', 240.00, NULL, 4.4, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(103, 38, 'Beef Tacos', 'Seasoned ground beef with salsa and sour cream.', 300.00, NULL, 4.6, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(104, 38, 'Shrimp Tacos', 'Grilled shrimp with cabbage slaw and chipotle sauce.', 350.00, NULL, 4.8, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(105, 39, 'Ribeye Steak', 'Juicy ribeye grilled to perfection with herb butter.', 650.00, NULL, 4.9, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(106, 39, 'Sirloin Steak', 'Lean sirloin steak with peppercorn sauce.', 580.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(107, 39, 'Tenderloin Steak', 'Soft tenderloin with mushroom sauce and fries.', 700.00, NULL, 4.9, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(108, 39, 'Chicken Steak', 'Grilled chicken breast with creamy mushroom sauce.', 450.00, NULL, 4.6, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(109, 39, 'Fish Steak', 'Grilled fish fillet with lemon butter sauce.', 500.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(110, 40, 'Caesar Salad', 'Romaine lettuce, croutons, parmesan and Caesar dressing.', 220.00, NULL, 4.5, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(111, 40, 'Greek Salad', 'Cucumber, tomato, feta cheese, olives and Greek dressing.', 240.00, NULL, 4.6, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(112, 40, 'Chicken Salad', 'Grilled chicken with mixed greens and honey mustard.', 280.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(113, 40, 'Tuna Salad', 'Flaked tuna with lettuce, egg, tomato and lemon vinaigrette.', 260.00, NULL, 4.5, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(114, 40, 'Fruit Salad', 'Fresh seasonal fruits with honey yogurt dressing.', 200.00, NULL, 4.4, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(115, 41, 'Belgian Waffle', 'Crispy waffle with maple syrup and butter.', 220.00, NULL, 4.6, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(116, 41, 'Chocolate Waffle', 'Waffle with Nutella, chocolate sauce and banana.', 260.00, NULL, 4.8, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(117, 41, 'Chicken Waffle', 'Fried chicken with waffle and honey butter.', 320.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(118, 41, 'Strawberry Waffle', 'Waffle with fresh strawberries and whipped cream.', 280.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(119, 41, 'Savory Waffle', 'Waffle with cheese, herbs and grilled vegetables.', 250.00, NULL, 4.4, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(120, 42, 'Chicken Seekh Kebab', 'Minced chicken kebab with Indian spices.', 280.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(121, 42, 'Mutton Seekh Kebab', 'Spicy minced mutton kebab grilled to perfection.', 320.00, NULL, 4.8, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(122, 42, 'Paneer Tikka', 'Grilled paneer cubes with mint chutney.', 240.00, NULL, 4.6, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(123, 42, 'Tandoori Chicken', 'Chicken marinated in yogurt and spices cooked in tandoor.', 350.00, NULL, 4.8, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(124, 42, 'Reshmi Kebab', 'Creamy chicken kebab with mild spices.', 300.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(125, 43, 'Garlic Butter Shrimp', 'Sauteed shrimp in garlic butter sauce.', 450.00, NULL, 4.8, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(126, 43, 'Grilled Fish', 'Fresh fish fillet grilled with lemon and herbs.', 420.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(127, 43, 'Fish and Chips', 'Crispy battered fish served with fries and tartar sauce.', 400.00, NULL, 4.6, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(128, 43, 'Chilli Squid', 'Crispy fried squid rings tossed in chilli sauce.', 380.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(129, 43, 'Butter Garlic Crab', 'Crab cooked in rich butter garlic sauce.', 550.00, NULL, 4.9, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(130, 44, 'Chicken Shawarma', 'Slow roasted chicken wrapped in pita with garlic sauce.', 250.00, NULL, 4.8, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(131, 44, 'Beef Shawarma', 'Spicy beef shawarma with tahini sauce and pickles.', 280.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(132, 44, 'Falafel Shawarma', 'Crispy falafel with hummus and fresh veggies.', 220.00, NULL, 4.5, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(133, 44, 'Mixed Shawarma', 'Combination of chicken and beef with double sauce.', 320.00, NULL, 4.8, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(134, 44, 'Shawarma Plate', 'Shawarma meat served with rice, salad and garlic sauce.', 350.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(135, 45, 'Chicken Sizzler', 'Sizzling chicken breast with vegetables and mashed potatoes.', 380.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(136, 45, 'Mix Veg Sizzler', 'Grilled vegetables, paneer and rice sizzling on hot plate.', 320.00, NULL, 4.5, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(137, 45, 'Fish Sizzler', 'Grilled fish fillet with lemon butter sauce and veggies.', 420.00, NULL, 4.7, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(138, 45, 'Paneer Sizzler', 'Spicy grilled paneer with bell peppers and rice.', 340.00, NULL, 4.6, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37'),
(139, 45, 'Steak Sizzler', 'Beef steak sizzling with fries, egg and peppercorn sauce.', 550.00, NULL, 4.9, 1, '2026-04-18 20:33:37', '2026-04-18 20:33:37');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'general',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `type`, `is_read`, `created_at`) VALUES
(1, 1, 'Order Placed — Cash on Delivery', 'Your order #1 has been placed. Please pay Rs. 440.00 on delivery.', 'order', 0, '2026-04-18 20:36:31'),
(2, 4, 'Order Placed — Cash on Delivery', 'Your order #2 has been placed. Please pay Rs. 480.00 on delivery.', 'order', 0, '2026-04-18 21:08:12');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `order_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','preparing','ready','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `payment_method` enum('cod','esewa','khalti','card') NOT NULL DEFAULT 'cod',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`order_id`, `user_id`, `total_amount`, `status`, `payment_method`, `created_at`, `updated_at`) VALUES
(1, 1, 440.00, 'pending', 'cod', '2026-04-18 20:36:31', '2026-04-18 20:36:31'),
(2, 4, 480.00, 'pending', 'cod', '2026-04-18 21:08:12', '2026-04-18 21:08:12');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `order_item_id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `item_id` int(10) UNSIGNED NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`order_item_id`, `order_id`, `item_id`, `quantity`, `price`) VALUES
(1, 1, 90, 1, 160.00),
(2, 1, 92, 1, 130.00),
(3, 1, 94, 1, 150.00),
(4, 2, 115, 1, 220.00),
(5, 2, 116, 1, 260.00);



-- --------------------------------------------------------

--
-- Table structure for table `order_history_hidden`
--

CREATE TABLE `order_history_hidden` (
  `hidden_id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `hidden_for_role` enum('chef','staff') NOT NULL,
  `hidden_by` int(10) UNSIGNED DEFAULT NULL,
  `hidden_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `transaction_uuid` varchar(100) DEFAULT NULL,
  `payment_method` enum('cod','esewa','khalti','card') NOT NULL,
  `payment_status` enum('pending','successful','failed') NOT NULL DEFAULT 'pending',
  `amount` decimal(10,2) NOT NULL,
  `gateway_ref` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('customer','chef','staff') NOT NULL DEFAULT 'customer',
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `email`, `password`, `role`, `phone`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Test Customer', 'customer@heraldcanteen.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', '9817456222', 1, '2026-04-18 19:56:25', '2026-04-18 21:44:52'),
(2, 'Main Chef', 'chef@heraldcanteen.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'chef', NULL, 1, '2026-04-18 19:56:25', '2026-04-18 19:56:25'),
(3, 'Delivery Staff', 'staff@heraldcanteen.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff', NULL, 1, '2026-04-18 19:56:25', '2026-04-18 19:56:25'),
(4, '25 cr wasted', 'green@heraldcanteen.com', '$2y$10$4W/.xgE8iuIdaPQxcqw69uXOHHo915Gk8JKdW0rGBKowgpHKFi2rm', 'customer', '9856784521', 1, '2026-04-18 21:06:25', '2026-04-18 21:23:12');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`cart_id`),
  ADD UNIQUE KEY `uq_cart_user_item` (`user_id`,`item_id`),
  ADD KEY `fk_cart_item` (`item_id`),
  ADD KEY `idx_cart_user` (`user_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`category_id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`attempt_id`);

--
-- Indexes for table `menu_items`
--
ALTER TABLE `menu_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `idx_menu_items_category` (`category_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_notifications_user_read` (`user_id`,`is_read`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `idx_orders_user` (`user_id`),
  ADD KEY `idx_orders_status` (`status`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`order_item_id`),
  ADD KEY `fk_order_items_item` (`item_id`),
  ADD KEY `idx_order_items_order` (`order_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_payments_order` (`order_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `cart_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `category_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `attempt_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `menu_items`
--
ALTER TABLE `menu_items`
  MODIFY `item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=140;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `order_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `order_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `fk_cart_item` FOREIGN KEY (`item_id`) REFERENCES `menu_items` (`item_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cart_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `menu_items`
--
ALTER TABLE `menu_items`
  ADD CONSTRAINT `fk_menu_items_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_order_items_item` FOREIGN KEY (`item_id`) REFERENCES `menu_items` (`item_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- ============================================================
-- SECURITY & FEATURE ADDITIONS (Herald Canteen v2)
-- ============================================================

-- Session management table (optional server-side sessions)
CREATE TABLE IF NOT EXISTS sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    last_activity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- notifications.type is already included in the CREATE TABLE above.
-- Index for faster unread lookups
CREATE INDEX idx_sessions_user ON sessions(user_id);
-- ============================================================
-- OTP / MFA additions (run otp_migration.sql on existing DBs)
-- These statements are idempotent for fresh installs.
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN `mfa_enabled`       TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`,
    ADD COLUMN `email_verified_at` DATETIME   NULL     DEFAULT NULL AFTER `mfa_enabled`;

CREATE TABLE IF NOT EXISTS `otp_tokens` (
  `otp_id`     int(10) UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`    int(10) UNSIGNED    NULL     DEFAULT NULL,
  `email`      varchar(150)        NOT NULL,
  `purpose`    enum('register','login','forgot_password','email_change','enable_mfa') NOT NULL,
  `otp_hash`   varchar(255)        NOT NULL,
  `expires_at` datetime            NOT NULL,
  `attempts`   tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `is_used`    tinyint(1)          NOT NULL DEFAULT 0,
  `new_email`  varchar(150)        NULL     DEFAULT NULL,
  `created_at` datetime            NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`otp_id`),
  KEY `idx_otp_email_purpose` (`email`, `purpose`),
  KEY `idx_otp_user_purpose`  (`user_id`, `purpose`),
  KEY `idx_otp_expires`       (`expires_at`),
  CONSTRAINT `fk_otp_user`
      FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
      ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE IF NOT EXISTS `pending_registrations` (
  `pending_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(20) NULL DEFAULT NULL,
  `role` ENUM('customer') NOT NULL DEFAULT 'customer',
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_pending_registration_email` (`email`),
  KEY `idx_pending_registration_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Mark existing/demo users as email-verified and keep MFA optional by default
UPDATE `users` SET `email_verified_at` = `created_at` WHERE `email_verified_at` IS NULL;
UPDATE `users` SET `mfa_enabled` = 0;


--
-- Indexes for table `order_history_hidden`
--
ALTER TABLE `order_history_hidden`
  ADD PRIMARY KEY (`hidden_id`),
  ADD UNIQUE KEY `uq_hidden_role_order` (`hidden_for_role`,`order_id`),
  ADD KEY `idx_hidden_order` (`order_id`),
  ADD KEY `idx_hidden_role` (`hidden_for_role`);


--
-- AUTO_INCREMENT for table `order_history_hidden`
--
ALTER TABLE `order_history_hidden`
  MODIFY `hidden_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;


-- ============================================================
-- PAYMENT / CHECKOUT FIXES APPENDED FOR FRESH INSTALLS
-- ============================================================
-- ============================================================
-- Herald Canteen — Payment / Checkout Fix Migration
-- MySQL + MariaDB safe version: no ALTER COLUMN IF NOT EXISTS.
-- Run this once after importing herald_canteen.sql on an existing database.
-- Safe to re-run.
-- ============================================================

-- ---------- Helper procedures for MySQL-safe conditional DDL ----------
DROP PROCEDURE IF EXISTS hc_add_column_if_missing;
DELIMITER //
CREATE PROCEDURE hc_add_column_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_column_name VARCHAR(64),
    IN p_ddl TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = p_column_name
    ) THEN
        SET @hc_sql = p_ddl;
        PREPARE hc_stmt FROM @hc_sql;
        EXECUTE hc_stmt;
        DEALLOCATE PREPARE hc_stmt;
    END IF;
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS hc_add_index_if_missing;
DELIMITER //
CREATE PROCEDURE hc_add_index_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_ddl TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND INDEX_NAME = p_index_name
    ) THEN
        SET @hc_sql = p_ddl;
        PREPARE hc_stmt FROM @hc_sql;
        EXECUTE hc_stmt;
        DEALLOCATE PREPARE hc_stmt;
    END IF;
END//
DELIMITER ;

-- ---------- Delivery locations ----------
CREATE TABLE IF NOT EXISTS `delivery_locations` (
  `location_id`   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `location_name` VARCHAR(150)    NOT NULL,
  `block_name`    VARCHAR(100)    NOT NULL,
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `sort_order`    INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`location_id`),
  UNIQUE KEY `uq_location_block` (`location_name`, `block_name`),
  KEY `idx_dl_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- Orders checkout columns ----------
SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `delivery_mode` ENUM(''delivery'',''takeaway'') NOT NULL DEFAULT ''delivery'' AFTER `payment_method`';
CALL hc_add_column_if_missing('orders', 'delivery_mode', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `special_notes` VARCHAR(500) NULL DEFAULT NULL AFTER `delivery_mode`';
CALL hc_add_column_if_missing('orders', 'special_notes', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `delivery_location_id` INT UNSIGNED NULL DEFAULT NULL AFTER `special_notes`';
CALL hc_add_column_if_missing('orders', 'delivery_location_id', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `delivery_location_name` VARCHAR(150) NULL DEFAULT NULL AFTER `delivery_location_id`';
CALL hc_add_column_if_missing('orders', 'delivery_location_name', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `delivery_block_name` VARCHAR(100) NULL DEFAULT NULL AFTER `delivery_location_name`';
CALL hc_add_column_if_missing('orders', 'delivery_block_name', @hc_sql);

SET @hc_sql = 'CREATE INDEX `idx_orders_delivery_location` ON `orders` (`delivery_location_id`)';
CALL hc_add_index_if_missing('orders', 'idx_orders_delivery_location', @hc_sql);

SET @hc_sql = 'CREATE INDEX `idx_payments_transaction_uuid` ON `payments` (`transaction_uuid`)';
CALL hc_add_index_if_missing('payments', 'idx_payments_transaction_uuid', @hc_sql);

-- ---------- KOT + invoice tables used by payment success/COD flow ----------
CREATE TABLE IF NOT EXISTS `kitchen_order_tickets` (
  `kot_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `delivery_mode` ENUM('delivery','takeaway') NOT NULL DEFAULT 'delivery',
  `special_notes` VARCHAR(500) NULL DEFAULT NULL,
  `kot_status`    ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`kot_id`),
  UNIQUE KEY `uq_kot_order` (`order_id`),
  KEY `idx_kot_status` (`kot_status`),
  KEY `idx_kot_user` (`user_id`),
  CONSTRAINT `fk_kot_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_kot_user`  FOREIGN KEY (`user_id`)  REFERENCES `users`  (`user_id`)  ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kot_invoices` (
  `invoice_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `invoice_token` CHAR(64) NOT NULL,
  `is_paid`       TINYINT(1) NOT NULL DEFAULT 0,
  `paid_at`       DATETIME NULL DEFAULT NULL,
  `downloaded_at` DATETIME NULL DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`invoice_id`),
  UNIQUE KEY `uq_invoice_order` (`order_id`),
  UNIQUE KEY `uq_invoice_token` (`invoice_token`),
  KEY `idx_invoice_user` (`user_id`),
  CONSTRAINT `fk_invoice_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_invoice_user`  FOREIGN KEY (`user_id`)  REFERENCES `users`  (`user_id`)  ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- Procedure called by COD/eSewa/Khalti handlers ----------
DROP PROCEDURE IF EXISTS `create_kot_and_invoice`;
DELIMITER //
CREATE PROCEDURE `create_kot_and_invoice`(
    IN p_order_id INT UNSIGNED,
    IN p_user_id INT UNSIGNED,
    IN p_delivery_mode VARCHAR(20),
    IN p_special_notes VARCHAR(500)
)
BEGIN
    DECLARE v_token CHAR(64);
    SET v_token = SHA2(CONCAT(UUID(), '-', p_order_id, '-', p_user_id, '-', RAND()), 256);

    INSERT INTO `kitchen_order_tickets`
        (`order_id`, `user_id`, `delivery_mode`, `special_notes`, `kot_status`)
    VALUES
        (p_order_id, p_user_id,
         CASE WHEN p_delivery_mode IN ('delivery','takeaway') THEN p_delivery_mode ELSE 'delivery' END,
         p_special_notes,
         'active')
    ON DUPLICATE KEY UPDATE
        `delivery_mode` = VALUES(`delivery_mode`),
        `special_notes` = VALUES(`special_notes`);

    INSERT INTO `kot_invoices`
        (`order_id`, `user_id`, `invoice_token`, `is_paid`)
    SELECT p_order_id, p_user_id, v_token, 0
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT 1 FROM `kot_invoices` WHERE `order_id` = p_order_id
    );
END//
DELIMITER ;

-- ---------- Seed delivery locations ----------
INSERT IGNORE INTO `delivery_locations`
  (`location_name`, `block_name`, `is_active`, `sort_order`)
VALUES
  ('IT & NOC',                               'WLV Block',      1, 10),
  ('RTE (Registry, Timetable & Examination)', 'WLV Block',      1, 20),
  ('Finance',                                'WLV Block',      1, 30),
  ('WLV SSD',                                'WLV Block',      1, 40),
  ('WLV PAT',                                'WLV Block',      1, 50),
  ('Academics A',                            'WLV Block',      1, 60),
  ('Academics B',                            'WLV Block',      1, 70),
  ('CEO Office',                             'WLV Block',      1, 80),
  ('ING SSD',                                'ING Block',      1, 110),
  ('ING PAT',                                'ING Block',      1, 120),
  ('PATIO',                                  'ING Block',      1, 130),
  ('Library',                                'Library Block',  1, 210),
  ('IMBA Lounge',                            'Library Block',  1, 220),
  ('AD (Admission Department)',              'Library Block',  1, 230),
  ('BD (Business Department)',               'HCK Block',      1, 310),
  ('HR (Human Resource)',                    'Resource Block', 1, 410),
  ('Academics C',                            'Resource Block', 1, 420),
  ('Academics D',                            'Resource Block', 1, 430),
  ('Academics E',                            'Resource Block', 1, 440),
  ('Academics F',                            'Resource Block', 1, 450),
  ('Academics G',                            'Resource Block', 1, 460),
  ('Academics H',                            'Resource Block', 1, 470),
  ('New Academics',                          'Resource Block', 1, 480);

-- ---------- Cleanup helper procedures ----------
DROP PROCEDURE IF EXISTS hc_add_column_if_missing;
DROP PROCEDURE IF EXISTS hc_add_index_if_missing;

-- Done.


-- ============================================================
-- ORDER HISTORY + IDEMPOTENT REORDER FIXES APPENDED FOR FRESH INSTALLS
-- ============================================================

DROP PROCEDURE IF EXISTS `hc_add_index_if_missing`;
DROP PROCEDURE IF EXISTS `hc_add_unique_index_if_missing`;

DELIMITER //
CREATE PROCEDURE `hc_add_index_if_missing`(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @hc_sql = p_sql;
        PREPARE hc_stmt FROM @hc_sql;
        EXECUTE hc_stmt;
        DEALLOCATE PREPARE hc_stmt;
    END IF;
END//

CREATE PROCEDURE `hc_add_unique_index_if_missing`(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @hc_sql = p_sql;
        PREPARE hc_stmt FROM @hc_sql;
        EXECUTE hc_stmt;
        DEALLOCATE PREPARE hc_stmt;
    END IF;
END//
DELIMITER ;

SET @hc_sql = 'CREATE INDEX `idx_orders_user_status_updated` ON `orders` (`user_id`, `status`, `updated_at`)';
CALL hc_add_index_if_missing('orders', 'idx_orders_user_status_updated', @hc_sql);

SET @hc_sql = 'CREATE INDEX `idx_order_items_order` ON `order_items` (`order_id`)';
CALL hc_add_index_if_missing('order_items', 'idx_order_items_order', @hc_sql);

SET @hc_sql = 'CREATE UNIQUE INDEX `uq_cart_user_item` ON `cart` (`user_id`, `item_id`)';
CALL hc_add_unique_index_if_missing('cart', 'uq_cart_user_item', @hc_sql);

SET @hc_sql = 'CREATE INDEX `idx_cart_user` ON `cart` (`user_id`)';
CALL hc_add_index_if_missing('cart', 'idx_cart_user', @hc_sql);

UPDATE `cart` c
JOIN `menu_items` m ON m.`item_id` = c.`item_id`
SET c.`total_price` = ROUND(c.`quantity` * m.`price`, 2)
WHERE c.`total_price` <> ROUND(c.`quantity` * m.`price`, 2);

DROP PROCEDURE IF EXISTS `hc_add_index_if_missing`;
DROP PROCEDURE IF EXISTS `hc_add_unique_index_if_missing`;
