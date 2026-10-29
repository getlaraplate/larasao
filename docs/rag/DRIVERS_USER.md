---
module: sao
audience: user
cross_cutting_user: false
---
# Connecting SAO to external systems — what to expect

SAO is a complete standalone tracker on its own. Connecting it to external systems — a version control host, a log or error source, another issue tracker — is **optional** and delivered as independent, switchable integrations called **drivers**. A driver is the code that knows how to talk to one kind of system; a **connection** is one configured instance of it (for example, your company's Redmine).

## What exists today

The connection framework and a set of bundled connectors are available. SAO ships drivers for issue trackers (Redmine, Jira, YouTrack, Linear, Azure DevOps, Gitea), code hosts that also serve issues and releases (GitHub, GitLab, Bitbucket), log and error sources (Sentry, Graylog, GlitchTip, Rollbar, Bugsnag, Honeybadger, Grafana, Datadog, Elastic, Better Stack), deployment and code-event sources (webhook and GitHub drivers), and a version probe (`http-version`) that asks a running application which version it serves. None of them is required: with no connection configured SAO works as a self-contained ticketing system, exactly as before.

## How connections work

A superadmin adds a connection from the panel: pick the driver, fill in the form the driver describes (endpoints and any credentials), and use a **test** button to check reachability. A few things are true by design:

- **Your secrets stay secret.** A connection's credential is stored encrypted and is **write-only** — once saved it is never shown back on screen; you replace it rather than read it. Alternatively an administrator can point a connection at a credential kept in the server environment, so it can be rotated without touching the panel at all.
- **One connection, many capabilities.** A single connection can offer several capabilities at once (for instance a code host that also serves issues and releases). You only enable the capabilities you actually want that connection to expose.
- **Status names are yours to map.** Because every installation names its statuses differently, SAO maps an external status onto its own canonical meaning through a small, editable table with sensible defaults — never a fixed guess.

You do not need to configure anything to use SAO; connections are optional.

## Which project does a log event belong to

When a log or error source pushes events to SAO, each event is matched to **one** project on its own. A superadmin controls the matching from the panel under **Correlation rules**:

- Choose what to compare: the project key, the **server host** (the machine that emitted the event) or the **request host** (the host of the page being served when it happened).
- Choose how: an exact comparison, or a wildcard one where `*` stands for any sequence of characters (`*.example.com`) and every other character, the dot included, is taken literally. Comparisons ignore upper and lower case.
- Pick the project and, optionally, an environment name such as `production`. The environment is only a label on the event; it does not create an environment record.
- Optionally limit a rule to one connection or one source profile.
- Rules run by priority (lower number first), then in creation order, and the first match wins. If no rule matches, SAO falls back to the project key in the event and then, when the connection serves a single project, to that project. An event that still matches nothing is kept as **Uncorrelated** so you can see it and write a rule for it.

A connection with no log binding to any project cannot route events at all: its events are recorded as **Discarded**, with the reason `no-logs-binding`. When a connection serves several projects, an event is never copied to all of them: it needs a rule. Rules and the project key only route to projects bound to the receiving connection.

Graylog sends the source of the message as the server host and reads the request host from the `application_url` field of the message, so for Graylog these are the values to build rules on. When no rule and no payload sets an environment, the server host is used as the environment name.

## Asking an environment which version it runs

The `http-version` connector is the active way to keep an environment's running version up to date: instead of waiting for a deploy notification, SAO reads the version from the application itself. It adds the **probe** capability. Create a connection with the `http-version` driver, enable the `probe` capability, and bind it to each project with a `probe` binding.

What is requested is always the **address set on the environment** (its base URL), plus a path. Nothing else is ever fetched: an address found inside an event or any other payload is ignored, so an event cannot make SAO call an arbitrary host.

Settings are set once in the connection's **config**, and a project's `probe` binding may override any of them in its own config (JSON), so one connection serves projects that expose the endpoint at different places (typically a different `path`):

- `path`: where the version is served, relative to the environment base URL. Default `/version`.
- `version_path`: where the version sits in the JSON answer, as a dot path (`app.version`). Default `version`.
- `commit_path` and `branch_path`: optional dot paths for the commit and the branch. When missing, or not text, the commit and branch are simply not updated.
- `timeout`: seconds to wait for the answer. Default 5, minimum 1.

The connection's optional **auth header** credential (a connection-only secret, not overridable per binding) is stored encrypted like any other secret and is written as `Name: value` (for example `X-Probe-Token: abc123`). It is checked before any request goes out; a header without a colon, with an empty or invalid name, or with line breaks is refused and nothing is sent. Its value never appears in a message or a log.

Security rules, all enforced by the driver:

- Only `http` and `https` base URLs with a host are accepted; an empty one, any other scheme, one with whitespace in it, one that is not a valid URL, or one with a user name or password embedded is refused without a request.
- Redirects are never followed: an answer in the 3xx range counts as a failure.
- The answer must be a JSON object. HTML, a bare number or text, a list, or an object without a usable version at `version_path` is a failure. A usable version is non-empty text or a whole number; a decimal (which would lose a trailing zero), a true/false value or a nested structure is not.
- The **test** button of the connection makes no request, because the probed address belongs to each environment; it only reports a malformed auth header or a connection address that is not http or https.

A failed probe changes nothing: the environment keeps what it had and, as without a probe, becomes stale once its version has not been seen for long enough. The reason (for example "The endpoint answered HTTP 404." or "The request failed or timed out.") is reported for that environment alone.
