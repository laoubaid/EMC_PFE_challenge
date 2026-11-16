<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial domain schema for regional carpooling platform.
 */
final class Version20261115000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initialize Carpooling Platform domain schema: User, Vehicle, City, Trip, Waypoint, Booking, Payment, Review, Notification, PricingConfig';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE waypoint (id INT AUTO_INCREMENT NOT NULL, stop_order INT NOT NULL, arrival_time DATETIME NOT NULL, distance_from_origin_km NUMERIC(10, 2) DEFAULT '0.00' NOT NULL, trip_id INT NOT NULL, city_id INT NOT NULL, UNIQUE INDEX uniq_trip_stop_order (trip_id, stop_order), INDEX IDX_B3DC5881A5BC2E0E (trip_id), INDEX IDX_B3DC58818BAC62AF (city_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql("CREATE TABLE booking (id INT AUTO_INCREMENT NOT NULL, seats_count INT NOT NULL, distance_km NUMERIC(10, 2) NOT NULL, price NUMERIC(10, 2) NOT NULL, status VARCHAR(40) DEFAULT 'confirmed' NOT NULL, created_at DATETIME NOT NULL, trip_id INT NOT NULL, passenger_id INT NOT NULL, board_waypoint_id INT NOT NULL, alight_waypoint_id INT NOT NULL, INDEX IDX_E00CEDDEA5BC2E0E (trip_id), INDEX IDX_E00CEDDE4502E565 (passenger_id), INDEX IDX_E00CEDDEB9CA0CE4 (board_waypoint_id), INDEX IDX_E00CEDDE2F6A4908 (alight_waypoint_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql("CREATE TABLE review (id INT AUTO_INCREMENT NOT NULL, rating INT NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, trip_id INT NOT NULL, author_id INT NOT NULL, target_id INT NOT NULL, UNIQUE INDEX uniq_review_trip_author_target (trip_id, author_id, target_id), INDEX IDX_794381C6A5BC2E0E (trip_id), INDEX IDX_794381C6F675F31B (author_id), INDEX IDX_794381C6158E0B66 (target_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql("CREATE TABLE city (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, UNIQUE INDEX UNIQ_2D5B02345E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql("CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, phone VARCHAR(30) DEFAULT NULL, is_admin TINYINT DEFAULT 0 NOT NULL, rating_avg DOUBLE PRECISION DEFAULT 4 NOT NULL, rating_count INT DEFAULT 10 NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql("CREATE TABLE payment (id INT AUTO_INCREMENT NOT NULL, amount NUMERIC(10, 2) NOT NULL, status VARCHAR(30) DEFAULT 'completed' NOT NULL, paid_at DATETIME NOT NULL, refunded_at DATETIME DEFAULT NULL, booking_id INT NOT NULL, UNIQUE INDEX UNIQ_6D28840D3301C60 (booking_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql("CREATE TABLE trip (id INT AUTO_INCREMENT NOT NULL, total_seats INT NOT NULL, status VARCHAR(30) DEFAULT 'scheduled' NOT NULL, departure_time DATETIME NOT NULL, created_at DATETIME NOT NULL, modified_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, driver_id INT NOT NULL, vehicle_id INT NOT NULL, INDEX IDX_7656F53BC3423909 (driver_id), INDEX IDX_7656F53B545317D1 (vehicle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql("CREATE TABLE pricing_config (id INT AUTO_INCREMENT NOT NULL, price_per_km NUMERIC(10, 2) NOT NULL, base_margin NUMERIC(6, 4) DEFAULT '0.0500' NOT NULL, effective_from DATETIME NOT NULL, updated_by_id INT DEFAULT NULL, INDEX IDX_4E196E42896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql("CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(50) NOT NULL, message LONGTEXT NOT NULL, is_read TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, trip_id INT DEFAULT NULL, INDEX IDX_BF5476CAA76ED395 (user_id), INDEX IDX_BF5476CAA5BC2E0E (trip_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql("CREATE TABLE vehicle (id INT AUTO_INCREMENT NOT NULL, brand VARCHAR(50) NOT NULL, model VARCHAR(50) NOT NULL, plate VARCHAR(30) NOT NULL, seats INT NOT NULL, owner_id INT NOT NULL, UNIQUE INDEX UNIQ_1B80E486719ED75B (plate), INDEX IDX_1B80E4867E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");

        $this->addSql("ALTER TABLE waypoint ADD CONSTRAINT FK_B3DC5881A5BC2E0E FOREIGN KEY (trip_id) REFERENCES trip (id) ON DELETE CASCADE");
        $this->addSql("ALTER TABLE waypoint ADD CONSTRAINT FK_B3DC58818BAC62AF FOREIGN KEY (city_id) REFERENCES city (id)");
        $this->addSql("ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEA5BC2E0E FOREIGN KEY (trip_id) REFERENCES trip (id)");
        $this->addSql("ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDE4502E565 FOREIGN KEY (passenger_id) REFERENCES `user` (id)");
        $this->addSql("ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEB9CA0CE4 FOREIGN KEY (board_waypoint_id) REFERENCES waypoint (id)");
        $this->addSql("ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDE2F6A4908 FOREIGN KEY (alight_waypoint_id) REFERENCES waypoint (id)");
        $this->addSql("ALTER TABLE review ADD CONSTRAINT FK_794381C6A5BC2E0E FOREIGN KEY (trip_id) REFERENCES trip (id) ON DELETE CASCADE");
        $this->addSql("ALTER TABLE review ADD CONSTRAINT FK_794381C6F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id)");
        $this->addSql("ALTER TABLE review ADD CONSTRAINT FK_794381C6158E0B66 FOREIGN KEY (target_id) REFERENCES `user` (id)");
        $this->addSql("ALTER TABLE payment ADD CONSTRAINT FK_6D28840D3301C60 FOREIGN KEY (booking_id) REFERENCES booking (id) ON DELETE CASCADE");
        $this->addSql("ALTER TABLE trip ADD CONSTRAINT FK_7656F53BC3423909 FOREIGN KEY (driver_id) REFERENCES `user` (id)");
        $this->addSql("ALTER TABLE trip ADD CONSTRAINT FK_7656F53B545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id)");
        $this->addSql("ALTER TABLE pricing_config ADD CONSTRAINT FK_4E196E42896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL");
        $this->addSql("ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE");
        $this->addSql("ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA5BC2E0E FOREIGN KEY (trip_id) REFERENCES trip (id) ON DELETE SET NULL");
        $this->addSql("ALTER TABLE vehicle ADD CONSTRAINT FK_1B80E4867E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DROP TABLE payment");
        $this->addSql("DROP TABLE booking");
        $this->addSql("DROP TABLE waypoint");
        $this->addSql("DROP TABLE review");
        $this->addSql("DROP TABLE notification");
        $this->addSql("DROP TABLE trip");
        $this->addSql("DROP TABLE vehicle");
        $this->addSql("DROP TABLE pricing_config");
        $this->addSql("DROP TABLE city");
        $this->addSql("DROP TABLE `user`");
    }
}
