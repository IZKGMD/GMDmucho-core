# Admin Roles & Permissions

MuchoCore supports custom administrator roles with explicit permissions.

## Built-in roles

The existing roles remain available:

- Owner
- Administrator
- Moderator
- Viewer

Built-in roles are system roles and keep their existing permission behavior.

## Custom roles

Owners can open:

~~~text
/admin/?page=roles
~~~

and create a role with:

- a stable role code;
- a display name;
- any permitted set of administrator permissions.

Permissions are enforced by the Admin Panel backend, not only hidden from the UI.

## Permission categories

The current permission catalog includes:

- dashboard and player access;
- player editing, password resets and deletion;
- level editing and rating;
- moderation, comments, messages and social management;
- music management;
- analytics, monitoring and security views;
- database and API tools;
- client tooling;
- backup access;
- server settings and system operations;
- administrator and role management;
- audit access;
- the administrator's own authentication security.

## Privilege-escalation protection

A non-owner role cannot create or update a custom role with permissions it does not already possess.

Likewise, assigning a custom role to another administrator requires the assigning administrator to possess the role's permission set.

Built-in system roles cannot be edited or deleted from the custom role manager.

A custom role that is still assigned to administrators cannot be deleted until those administrators are reassigned.

## Migration

RBAC storage is created automatically by the database migration:

~~~text
database/migrations/20260925_010_admin_rbac.php
~~~

Normal deployment applies it automatically.

## Assignment

The owner creates an administrator from:

~~~text
/admin/?page=admins
~~~

The role selector includes both built-in and custom roles.

Each administrator continues to use an individual account and authentication factors.
