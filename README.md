 <img src="screenshot/create-ticket.png" width="100%"></img> 
## Helpdesk Laravel

The Helpdesk Laravel repository is a project aimed at providing a web-based helpdesk system using [**Laravel 12**](https://laravel.com) and [**Filament 4**](https://github.com/filamentphp/filament). Helpdesk is a system that allows users to submit questions, request assistance, or report issues related to a company's products or services.

In this repository, you will find the complete source code implemented using Laravel, a popular and powerful PHP framework. This project is designed to assist web developers in building and managing helpdesk systems with ease.

The key features of Laravel Helpdesk include:
1. Ticket Submission: Users can submit new tickets containing their questions, assistance requests, or issue reports.
2. Ticket Management: Admins can view, assign, or close tickets submitted by users.
3. Ticket Prioritization: Users can prioritize their tickets to emphasize the level of urgency.
4. History and Tracking: The system records all activities and conversations within tickets, allowing for easy tracking and auditing.

This Laravel Helpdesk repository will provide a solid foundation for building a customizable and extensible helpdesk system according to your specific needs. By utilizing Laravel as the main framework, this project offers user-friendliness, flexibility, and good performance.

Feel free to explore this repository and start building a robust and responsive helpdesk application using Laravel Helpdesk!

## MCP account prerequisite and ticket intake

The application exposes one vendor-neutral `helpdesk_intake` MCP tool with exactly two business paths: use an existing eligible reporter account and create its ticket, or create the required reporter account first and then create its ticket in the same intake. Account creation exists only as a ticket prerequisite. MCP does not expose ticket lookup, comments, updates, workflow actions, or administration.

Codex, Atlas relaying WhatsApp, and other AI hosts or channel bridges are generic MCP clients or gateways; they all use the same contract and do not change Helpdesk behavior. Ticket ownership remains tied to a verified WhatsApp number and is resolved against the Helpdesk user directory or Talenta. Generic and unsigned channels link through WhatsApp OTP when they provide a stable `external_user_id`; a trusted WhatsApp webhook gateway may use a signed one-event assertion. If a verified number is absent from both directories, MCP asks for explicit registration consent and a freshly typed full name, atomically creates a phone-only account with null email and password, then continues the original ticket intake. Declining account creation cancels the intake without creating an account or ticket. A direct MCP client that omits `external_user_id` must verify by OTP on every new intake and does not receive a durable reporter binding.

Setup, tool contract, gateway requirements, retry behavior, and security notes are in [docs/mcp/README.md](docs/mcp/README.md).

### MCP production quick start

The production MCP endpoint runs inside the same Laravel web application; it does not need a separate MCP daemon. Deploy the application behind HTTPS, configure a bearer token and the WhatsApp OTP gateway, and expose:

```text
https://helpdesk.example.com/mcp/helpdesk
```

Ready-to-copy setup instructions are available for [Codex](docs/mcp/README.md#connect-codex), [Cursor](docs/mcp/README.md#connect-cursor), [Google Antigravity](docs/mcp/README.md#connect-google-antigravity), [Atlas/WhatsApp gateways](docs/mcp/README.md#connect-atlas-or-a-whatsapp-gateway), and [other Streamable HTTP clients](docs/mcp/README.md#connect-another-mcp-client). The complete production checklist is in the [MCP production quickstart](docs/mcp/README.md#production-quickstart); upgrades of an existing database must also follow the [safe cutover runbook](docs/mcp/README.md#install).

After the client shows the single `helpdesk_intake` tool, try this portable prompt:

```text
Buat laporan helpdesk: printer kasir tidak bisa mencetak sejak pagi.
```

`/helpdesk printer kasir tidak bisa mencetak` also starts the intake when the host forwards it as normal message text. Connecting an MCP server does not automatically install a slash command in every AI client, so use the natural-language prompt when `/helpdesk` is intercepted by the host UI.

<hr/>

## Database Design
 <img src="screenshot/database-design.png" width="100%"></img> 

<hr/>

## Unified Modeling Language (UML)
<img src="screenshot/uml.png" width="100%"></img> 
<hr/>

## Requirements
* PHP 8.2 or higher
* Laravel 12.x
* Filament 4.x
* Database (eg: MySQL, PostgreSQL, SQLite)
* Web Server (eg: Apache, Nginx, IIS)

<hr/>


## Installation

The commands below are for local development. Production upgrades must not use a rolling mixed-version deploy or run the dummy seeder. Follow the maintenance-window, migration-audit, reviewed-admin bootstrap, session purge, and smoke-test runbook in [docs/mcp/README.md](docs/mcp/README.md#install).

* Install [Composer](https://getcomposer.org/download)
* Clone the repository: `git clone https://github.com/apriansyahrs/cs_helpdesk.git`
* Install PHP dependencies: `composer install`
* Setup configuration: `cp .env.example .env`
* Generate application key: `php artisan key:generate`
* Create a database and update your configuration.
* Run database migration: `php artisan migrate`
* Run database seeder: `php artisan db:seed`
* Create a symlink to the storage: `php artisan storage:link`
* Run the dev server: `php artisan serve`

<hr/>

## Development Dummy Accounts

These credentials are created by development seed data only. Never create or retain these fixed passwords in production.

### Super Admin
> - Email: superadmin@cs.com
> - Password: password
### Admin Unit
> - Email: adminunit@cs.com
> - Password: password
### Staff Unit
> - Email: staffunit@cs.com
> - Password: password
### General User
> - Email: user@cs.com
> - Password: password

## Super Admin Preview
 <img src="screenshot/super-admin.png" width="100%"></img> 
