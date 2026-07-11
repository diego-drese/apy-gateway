# Development Specification

## Philosophy

The primary goal is to produce software that remains maintainable for years.

Every implementation must prioritize:

- Readability
- Predictability
- Scalability
- Low coupling
- High cohesion
- Explicit behavior over magic
- Performance
- Testability

Code is written for future developers, not only for the compiler.

---
# Development Environment

Docker is the default development environment.

Every new project must provide a complete Docker environment capable of running the entire application locally.

The repository should include:

- Docker Compose
- Application container
- Database container(s)
- Queue worker
- Scheduler (when applicable)
- Supporting services (Redis, MinIO, Mailpit, etc.)

A new developer must be able to start the project with a single command.

Example:

docker compose up -d

---

# Legacy Systems

Never develop directly on legacy environments.

When integrating with an existing system, follow this priority:

1. Create a Docker environment that reproduces the legacy application.
2. If containerization is technically impossible, develop against a remote development or staging environment.
3. Only as a last resort should code be developed directly for the legacy platform.

Legacy environments should never dictate the architecture of new code.

New code must remain isolated, modular, and easily portable.

---

# Environment Independence

The application should not depend on:

- specific operating systems
- local software installations
- manually configured services
- developer-specific machine settings

Everything required to run the project must be versioned.

Environment setup should be reproducible.

"If it doesn't run inside Docker, it isn't considered reproducible."

---

# Local Development

Local development should never require:

- installing PHP manually
- installing MySQL manually
- installing Redis manually
- configuring Apache or Nginx manually

Docker is responsible for providing every dependency whenever possible.

# General Rules

- Always use `declare(strict_types=1);`
- Follow PSR-12.
- Prefer composition over inheritance.
- Never write undocumented magic behavior.
- Every class must have a single responsibility.
- Keep methods small and focused.
- Avoid side effects.
- Avoid duplicated code.
- Prefer explicit code over clever code.

---

# Architecture

Business rules must never live inside:

- Controllers
- Nova Resources
- Models
- Console Commands
- Jobs

Business logic belongs inside dedicated Action or Service classes.

Controllers should only:

- Validate
- Authorize
- Call an Action
- Return a Response

Models should only represent data and relationships.

Jobs should only orchestrate asynchronous execution.

Commands should only orchestrate application workflows.

---

# Naming

Class names must clearly describe their responsibility.

Good examples:

CreateInvoiceAction

ImportStudySeriesAction

NormalizePatientNameAction

Bad examples:

Helper

Utils

Manager

Processor

Service

Methods should always read like sentences.

Examples:

calculateInvoiceTotal()

copyStudiesToStage()

normalizeIssuer()

findPatient()

Never use abbreviations unless they are industry standards.

---

# SOLID Principles

Every implementation should follow SOLID.

Especially:

- Single Responsibility
- Dependency Injection
- Interface Segregation

Never instantiate dependencies manually when they can be injected.

---

# Dependency Injection

Always inject dependencies.

Avoid:

new Something()

Prefer:

Constructor Injection

or

Laravel Container

---

# Database

Always prefer Eloquent.

Avoid raw SQL unless performance requires it.

When raw SQL is required:

- document why
- keep it isolated

Always:

- eager load relationships
- avoid N+1
- paginate large datasets
- process imports using chunks

Never load thousands of records into memory.

---

# Performance

Performance is a feature.

Always consider:

- Query count
- Memory usage
- CPU usage
- Queue execution time
- Cache opportunities

Use lazy collections when processing millions of records.

Process imports using chunking.

Avoid unnecessary object creation.

Cache expensive operations.

---

# Error Handling

Never silently ignore exceptions.

Every exception should either:

- be handled
- be logged
- be rethrown

Never use empty catch blocks.

---

# Logging

Logs should provide useful information.

Include:

- identifiers
- execution time
- affected records
- important parameters

Never log sensitive information.

---

# Configuration

Never hardcode:

- URLs
- Credentials
- API Keys
- Paths

Everything configurable belongs in:

.env

or

config/*.php

---

# Comments

Comments explain WHY.

Never explain WHAT.

Bad:

// increment counter

Good:

// The external PACS may resend duplicated studies.
// This prevents duplicated imports.

---

# Documentation

Every complex class should contain a short description explaining:

- responsibility
- inputs
- outputs

Public APIs should be documented.

---

# Code Style

Prefer early returns.

Avoid nested if statements.

Bad:

if (...) {
if (...) {
...
}
}

Good:

if (...) {
return;
}

...

Keep indentation shallow.

---

# Collections

Prefer Collection methods.

Avoid long foreach chains when Collection methods improve readability.

But:

Never sacrifice performance just to make code "functional".

---

# Validation

Always validate external input.

Use Form Requests whenever possible.

Never trust external systems.

---

# Queue Jobs

Jobs must be idempotent.

A job may execute multiple times without causing duplicated data.

Every long-running job should:

- define timeout
- define retry policy
- report progress when appropriate

---

# Imports

Large imports must:

- process using chunks
- be resumable
- support retries
- be idempotent
- record execution metrics

Never assume external data is valid.

---

# Caching

Cache only expensive operations.

Always define cache invalidation strategy.

Avoid stale caches.

---

# API Design

Responses must be predictable.

Never mix formats.

Prefer Resources.

Keep response contracts stable.

---

# Security

Always validate permissions.

Escape user input.

Never trust uploaded files.

Never expose internal exceptions.

Secrets never belong in the repository.

---

# Testing

Critical business rules must be testable.

Prefer integration tests for workflows.

Prefer unit tests for pure business logic.

---

# Refactoring

Leave the code better than you found it.

Avoid large files.

Prefer extracting classes instead of adding more methods.

---

# Git

Commits must represent one logical change.

Commit messages must be written in English.

Examples:

Add study migration metrics

Fix patient normalization

Improve issuer cache

Never commit broken code.

---

# Laravel Guidelines

Prefer:

- Actions
- DTOs
- Form Requests
- Policies
- Resources
- Events
- Queued Jobs

Avoid fat Models.

Avoid fat Controllers.

Avoid Helpers unless truly generic.

---

# AI Coding Rules

The AI should never:

- invent business rules
- rename existing public APIs without reason
- introduce unnecessary dependencies
- overengineer simple solutions
- optimize prematurely

When requirements are ambiguous:

Ask first.

When refactoring:

Preserve behavior.

When generating code:

Follow the existing project architecture before introducing new patterns.

Always prefer consistency over novelty.

---

# Final Principle

Every decision should answer:

"Will another developer understand this in six months?"

If the answer is no,

rewrite it.
