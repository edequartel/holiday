CREATE TABLE trips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  destination VARCHAR(255),
  start_date DATE,
  end_date DATE,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE itinerary_days (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  day_date DATE NOT NULL,
  location VARCHAR(255),
  title VARCHAR(255),
  details TEXT,
  transport VARCHAR(255),
  hotel VARCHAR(255),
  url VARCHAR(1000),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE flights (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  flight_date DATE,
  airline VARCHAR(255),
  flight_number VARCHAR(50),
  departure_airport VARCHAR(100),
  arrival_airport VARCHAR(100),
  departure_time TIME,
  arrival_time TIME,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE packing_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  category VARCHAR(100),
  item VARCHAR(255) NOT NULL,
  quantity VARCHAR(100),
  packed TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE map_points (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  point_type ENUM('hotel','parking','poi','restaurant','transport','other') NOT NULL DEFAULT 'poi',
  name VARCHAR(255) NOT NULL,
  address VARCHAR(255),
  city VARCHAR(255),
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  notes TEXT,
  source VARCHAR(50) DEFAULT 'manual',
  show_on_map TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE day_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  day_id INT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  mime_type VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
  file_size INT NOT NULL DEFAULT 0,
  file_hash CHAR(64) NULL,
  notes TEXT,
  extracted_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  FOREIGN KEY (day_id) REFERENCES itinerary_days(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE day_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  day_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  url VARCHAR(1000) NOT NULL,
  notes TEXT,
  extracted_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  FOREIGN KEY (day_id) REFERENCES itinerary_days(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
