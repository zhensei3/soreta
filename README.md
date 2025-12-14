# soreta

A Knowledge-based Troubleshooting Guide and Appointment System for A.D. Soreta Electronics Enterprises.

Customers can consult basic troubleshooting guides for appliances and, if the issue can't be resolved, book a service appointment. The system supports two user roles: admin and customer.

## Table of Contents
- [About](#about)
- [Key Features](#key-features)
- [User Roles](#user-roles)
- [Typical Customer Flow](#typical-customer-flow)
- [Typical Admin Flow](#typical-admin-flow)
- [Requirements](#requirements)
- [Installation (example)](#installation-example)
- [Usage](#usage)
- [Development & Testing](#development--testing)
- [Contributing](#contributing)
- [License](#license)
- [Contact](#contact)

## About
soreta is built to help A.D. Soreta Electronics Enterprises reduce unnecessary technician visits by giving customers a guided troubleshooting experience. When a customer cannot resolve their appliance problem with the provided steps, they can schedule a repair appointment which the company can manage.

## Key Features
- Browse troubleshooting guides by appliance type and symptom
- Step-by-step basic fixes for common appliance problems
- Appointment booking for on-site service when troubleshooting fails
- Admin interface to manage guides, appointments, and customers
- Minimal roles: admin and customer

## User Roles
- Admin
  - Create, edit, and delete troubleshooting guides
  - View and manage appointment requests
  - Update appointment statuses (scheduled, in-progress, completed, cancelled)
  - Manage customer data
- Customer
  - Browse troubleshooting guides
  - Follow step-by-step instructions
  - Book an appointment if the issue persists
  - View appointment status/history

## Typical Customer Flow
1. Customer visits the site and selects appliance/symptom.
2. Customer follows the troubleshooting guide (text, images, or checklists).
3. If the issue is unresolved, the customer fills an appointment form (preferred date/time, address, contact).
4. The appointment is created in the system and visible to admins for scheduling.

## Typical Admin Flow
1. Admin reviews incoming appointment requests.
2. Admin assigns technicians / confirms appointment time.
3. Admin updates appointment status and records notes/results after service.
4. Admin maintains/update troubleshooting guides based on field reports.

## Requirements (example)
- PHP >= 8.0
- Composer
- Web server (Apache / Nginx)
- MySQL or MariaDB (or another supported DB)
- PHP extensions: pdo_mysql, mbstring, json, etc.

Adjust these based on the actual stack used.

## Installation (example)
Clone and install dependencies:
```bash
git clone https://github.com/zhensei3/soreta.git
cd soreta
composer install
cp .env.example .env
# Edit .env to set database and app settings
php artisan key:generate   # if using Laravel; adapt for your framework
```
Run migrations and seed demo data (if applicable):
```bash
php artisan migrate --seed
```
Start local server (framework-specific):
```bash
php -S localhost:8000 -t public
# or use the framework's serve command
```

## Usage
- As a customer: browse guides, follow steps, and use the "Book Appointment" form if needed.
- As an admin: log in to the admin panel to manage guides and appointments.

Add screenshots, sample requests, or URLs here for clarity (e.g., /admin, /guides, /appointments).

## Development & Testing
- Run test suite (adjust to your test runner):
```bash
composer test
```
- Use linters/formatters as configured:
```bash
composer cs-check
composer cs-fix
```

## Contributing
Contributions are welcome.
- Open an issue to discuss major changes.
- Fork the repository, create a feature branch, add tests, and submit a pull request.
- Keep commits focused and add descriptive PR titles.

## License
Specify a license (e.g., MIT). Add a LICENSE file to the repo.

## Contact
Maintainer: @zhensei3  
For A.D. Soreta Electronics Enterprises inquiries, include an official contact email or link here.
