# Tasks: Laravel Workflow Package

**Input**: Design documents from `/specs/003-laravel-workflow-package/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story, ensuring clean code and SOLID design.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Include exact file paths in descriptions

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Project initialization and basic structure

- [x] T001 Create package directory structure (Commands, Contracts, Enums, Facades, Models, Services, Traits) in `src/`
- [x] T002 Configure `composer.json` with PSR-4 autoloading for `LaravelWorkflow` and test dependencies
- [x] T003 [P] Configure `vendor/bin/pint` and `.editorconfig` for strict project formatting standards
- [x] T004 [P] Setup `tests/Pest.php` and `tests/TestCase.php` with necessary package bootstrapping

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core infrastructure and Enums required for all features

- [x] T005 [P] Implement PHP Enums for all status and type fields (WorkflowType, StepType, etc.) in `src/Enums/`
- [x] T006 Implement base migration for `workflow_` tables (Definitions and Runtime) in `database/migrations/`
- [x] T007 [P] Create `HasWorkflow` trait for polymorphic subject support in `src/Traits/HasWorkflow.php`
- [x] T008 Implement `LaravelWorkflowServiceProvider.php` to register migrations, config, and singleton services
- [x] T009 [P] Define core Interfaces/Contracts for custom logic in `src/Contracts/` (CustomAuthorizer, ConditionEvaluator, etc.)

---

## Phase 3: User Story 1 - Workflow Design & Activation (Priority: P1) 🎯 MVP

**Goal**: Enable creation and activation of versioned workflow blueprints.

**Independent Test**: Create a workflow with 1 start and 1 end step, add a transition, and successfully change status to `active`.

### Tests for User Story 1

- [x] T010 [P] [US1] Create `tests/Feature/WorkflowDefinitionTest.php` for activation rules (start/end step requirements)
- [x] T011 [P] [US1] Create `tests/Unit/WorkflowVersioningTest.php` for blueprint immutability rules

### Implementation for User Story 1

- [x] T012 [P] [US1] Implement `Workflow` and `WorkflowStep` models in `src/Models/` with UUID and audit traits
- [x] T013 [P] [US1] Implement `WorkflowTransition` and `WorkflowCondition` models in `src/Models/`
- [x] T014 [US1] Implement `WorkflowService::activate()` with validation for start/end steps in `src/Services/WorkflowService.php`
- [x] T015 [US1] Implement versioning logic (cloning definition on structural edit) in `src/Services/WorkflowService.php`
- [x] T016 [US1] Create `WorkflowFactory` and `WorkflowStepFactory` in `database/factories/` for testing support

---

## Phase 4: User Story 2 - Human-Driven Approval (Priority: P2)

**Goal**: Deterministic resolution of available actions and advancement of human-gated steps.

**Independent Test**: Start an instance, query available actions for an authorized user, and perform an `approve` action.

### Tests for User Story 2

- [x] T017 [P] [US2] Create `tests/Feature/ActionResolutionTest.php` for eligibility and availability logic
- [x] T018 [P] [US2] Create `tests/Feature/WorkflowAdvancementTest.php` for state transitions and advancement

### Implementation for User Story 2

- [x] T019 [P] [US2] Implement `WorkflowInstance` and `WorkflowStepInstance` models in `src/Models/`
- [x] T020 [P] [US2] Implement `WorkflowAssignment` and `WorkflowHistory` models in `src/Models/`
- [x] T021 [US2] Implement `ActionResolver` service in `src/Services/ActionResolver.php` (Eligibility -> Gathering -> Availability)
- [x] T022 [US2] Implement `ConditionEvaluator` service in `src/Services/ConditionEvaluator.php` (Expression & Custom)
- [x] T023 [US2] Implement `WorkflowEngine::performAction()` in `src/Services/WorkflowEngine.php` with transaction support
- [x] T024 [US2] Add `requires_comment` validation logic in the perform action flow

---

## Phase 5: User Story 3 - System-Driven Automation (Priority: P3)

**Goal**: Automatic execution of steps and transitions without human intervention.

**Independent Test**: Enter an `automated` step and verify the handler executes and advances to the next step immediately.

### Tests for User Story 3

- [x] T025 [P] [US3] Create `tests/Feature/AutomationPipelineTest.php` for handler execution and auto-transitions
- [x] T026 [P] [US3] Create `tests/Feature/AutomationFailureTest.php` for error handling and retry logic

### Implementation for User Story 3

- [x] T027 [US3] Implement handler resolution logic in `src/Services/WorkflowEngine.php` using the service container
- [x] T028 [US3] Implement `automatic` and `conditional` transition evaluation by priority in `src/Services/WorkflowEngine.php`
- [x] T029 [US3] Implement failure recording and retry mechanism (new step instance) for failed automated steps
- [x] T030 [US3] Add automated history logging for system events in `src/Services/WorkflowEngine.php`

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Final refinements and adherence to SOLID/Clean code.

- [x] T031 [P] Ensure all services are injected via the container (DI) and adhere to Single Responsibility Principle
- [x] T032 [P] Implement `LaravelWorkflow` Facade for a clean developer interface
- [x] T033 Add multi-tenancy Global Scope to all definition and runtime models
- [x] T034 [P] Run `vendor/bin/pint --format agent` on all source files
- [x] T035 Create `php artisan workflow:list` and `workflow:check` commands in `src/Commands/`
- [x] T036 Final documentation pass on docblocks and PHPDoc types

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)** -> **Foundational (Phase 2)**: Core structure must exist before migrations/enums.
- **Foundational (Phase 2)** -> **User Story 1 (Phase 3)**: Models and Enums must exist before blueprint logic.
- **User Story 1 (Phase 3)** -> **User Story 2 (Phase 4)**: Blueprints must be activate-able before instances can run.
- **User Story 2 (Phase 4)** -> **User Story 3 (Phase 5)**: Advancement logic must exist before automation can chain it.

### Parallel Opportunities

- All Enums (T005) can be developed in parallel with the Migration (T006).
- Test classes (T010, T017, T025) can be drafted in parallel with their respective model definitions.
- CustomResolver and CustomAuthorizer contracts (T009) are independent.

---

## Implementation Strategy

### MVP First (User Story 1 Only)
1. Complete Setup and Foundational.
2. Complete US1 (Blueprints and Activation).
3. **Validate**: Create a seeder that defines a workflow and verify it passes activation checks.

### Incremental Delivery
- Each User Story adds a layer: Blueprint -> Human Advancement -> Automation.
- Each story includes its own history logging and history retrieval tasks to keep them testable.
- SOLID principles: Logic is separated into `ActionResolver`, `ConditionEvaluator`, and `WorkflowEngine` services.
