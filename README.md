# Icinga Business Process Modeling

![Icinga Logo](https://icinga.com/wp-content/uploads/2014/06/icinga_logo.png)

If you want to visualize and monitor hierarchical business processes based on
any or all objects monitored by Icinga, the Icinga Web 2 business process
module is the way to go.

This version is the PostgreSQL-only Greenfield implementation. Process
definitions are versioned structured JSON documents stored through the central
Icinga Kubernetes API. The module neither reads nor writes local process files
and does not support the historic text format. Kubernetes/OpenShift members can
be selected dynamically by cluster, namespace, GVK, name, labels, owner and
state; their current state is resolved through the API without direct database
or cluster access. Every selector field is optional: a label-only selector such
as `app=portal` intentionally follows matching resources across GVK/version
changes introduced by an operator. Add `kind` or the complete GVK only when the
business rule actually requires that identity.

When a fixed Kubernetes node is created, the editor obtains the local and
configured direct clusters without probing remote endpoints, then loads the
GVKs actually present in the selected cluster. This includes generic CRDs
introduced by operators. Object suggestions are server-side, prefix-filtered
and bounded to 50 items; their labels include cluster, full GVK, namespace,
name and non-live freshness. The stored node keeps cluster, GVK and stable
global UUID explicitly, so similarly named or versioned external objects
cannot be confused.

The anonymous Actuator-style Public Health API is disabled by default and must
first be enabled globally with
`ICINGA_BUSINESSPROCESS_PUBLIC_HEALTH_ENABLED=true`; publication still requires
the separate configuration and node opt-ins described in
`doc/40-Public-Health-API-Concept.md`.

![Preview](doc/screenshot/00_preview/0005_readme-preview.png)

Want to create custom process-based dashboards? Trigger notifications at
process or sub-process level? Provide a quick top-level view for thousands of
components on a single screen? That's what this module has been designed for!

You're running a huge cloud, want to get rid of the monitoring noise triggered
by your auto-scaling platform but still want to have detailed information just
a couple of clicks away in case you need them? You will love this little module!

Documentation
-------------

### Basics
* [Installation](doc/02-Installation.md)
* [Getting Started](doc/03-Getting-Started.md)
* [Create your first process node](doc/04-Create-your-first-process-node.md)
* [Importing Processes](doc/05-Importing-Processes.md)
* [Customize Node Order](doc/06-Customize-Node-Order.md)
* [State Overrides](doc/07-State-Overrides.md)
* [Operators](doc/09-Operators.md)
* [Controlling Access](doc/31-Permissions.md)

### Web Components
* [Breadcrumb](doc/12-Web-Components-Breadcrumb.md)
* [Tile Renderer](doc/13-Web-Components-Tile-Renderer.md)
* [Tree Renderer](doc/14-Web-Components-Tree-Renderer.md)
* [Show Processes on a Dashboard](doc/16-Add-To-Dashboard.md)

### Storage
* [Store your Configuration](doc/21-Store-Config.md)
* [Import or export a structured Definition](doc/22-Upload-Config.md)
* [Public Health API](doc/40-Public-Health-API-Concept.md)

Development tests
-----------------

The standalone Public Health HA regression starts three isolated local PHP
servers, sends concurrent requests through every replica, simulates loss and
recovery of the authoritative API backend and verifies immediate sanitized
`UP/200` to `UNKNOWN/503` transitions without caching a last green state. It
then restarts the three replicas one after another on their existing endpoints
and submits 60 concurrent calculations after every restart. Correct current
states must remain available and the complete rolling restart must finish
within 30 seconds:

```powershell
.\test\php\run-public-health-concurrency.ps1
```

The Kubernetes state regression keeps one Business Process object alive while
the API changes resource versions and states, becomes stale, fails completely
and recovers. It then executes 200 further alternating calculations to ensure
the request-local repository cache never crosses a calculation boundary:

```powershell
.\test\php\run-kubernetes-state-refresh.ps1
```

The inventory-selection regression uses a real local HTTP boundary and checks
the non-probing direct-cluster inventory, operator CRD discovery, bounded GVK
search, unambiguous labels and fail-closed cross-cluster identity validation:

```powershell
.\test\php\run-kubernetes-inventory-selection.ps1
```

Direct Kubernetes object selection also provides an optional namespace choice derived from matching inventory. It does not require collected Namespace objects. Public Health configuration URLs use a stored, editable path prefilled from the process ID; see the Public Health documentation for publication and node-path rules.
