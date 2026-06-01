# Laravel 12 Reddit Data Collection System --- Existing Project Enhancement

I already have a Laravel 12 Reddit keyword research project partially
implemented.

Now I want to improve, correct, optimize, and extend the existing
implementation.

I need a production-ready, scalable, clean architecture solution using
Laravel 12 best practices.

# IMPORTANT

Act as an experienced senior Laravel developer and software architect
while generating the solution.

The implementation must be:

-   Clean
-   Neat
-   Reusable
-   Modular
-   Scalable
-   Maintainable
-   Optimized
-   Production-ready

Follow:

-   Laravel 12 best practices
-   SOLID principles
-   Clean Architecture principles
-   Enterprise-level coding standards
-   Proper separation of concerns

# Current Status

The following functionality is ALREADY implemented in my project:

-   Step 1 --- User submits keyword
-   Step 2 --- Store keyword in `searches` table
-   Step 3 --- Fetch Reddit search API data
-   Step 4 --- Store 100 Reddit results in `results` table

However:

-   Some required fields are not stored properly
-   Some relationships need correction
-   Existing architecture needs optimization
-   Some code should be refactored
-   Missing functionality should now be implemented

# IMPORTANT INSTRUCTION

Do NOT rebuild the project from scratch unnecessarily.

Instead:

-   Review the existing implementation
-   Correct existing code where needed
-   Preserve working functionality
-   Improve architecture
-   Refactor repetitive logic
-   Add missing database fields
-   Add missing relationships
-   Optimize performance
-   Implement missing functionality cleanly

# Existing Workflow

## Step 1 --- User Submits Keyword

User submits a keyword from a form.

Example:

    seo tools

# Step 2 --- Store Search Keyword (Already Implemented)

Keyword is already stored in `searches` table.

Current columns:

    id
    keyword
    status
    created_at
    updated_at

IMPORTANT:

-   `status` column already exists
-   Preserve existing functionality
-   Improve only where necessary

# Step 3 --- Fetch Reddit Search Results (Already Implemented)

Current Reddit API:

    https://www.reddit.com/search.json?q={keyword}&limit=100

Example:

    https://www.reddit.com/search.json?q=seo tools&limit=100

# Step 4 --- Store Reddit Search Results (Already Implemented)

The `results` table already exists and data is already being stored.

Now review the implementation and:

-   Correct missing field storage
-   Add newly required columns
-   Improve indexing
-   Improve relationships
-   Optimize insert/update logic
-   Refactor repetitive code into reusable services/helpers
-   Improve naming conventions
-   Improve maintainability

# Existing Database Relationship

`results.search_id` already exists as foreign key.

Relationship:

    searches
       -> hasMany(results)

    results
       -> belongsTo(searches)

# IMPORTANT NEW REQUIREMENT

After storing the 100 Reddit results, automatically fetch detailed
Reddit post data for every result.

This process should happen automatically immediately after form
submission.

No manual trigger should be required.

# Reddit Detail API Logic

Each record inside `results` table contains:

    permalink

Example:

    {
      "permalink": "/r/SEO/comments/abc123/sample_post/"
    }

Use this permalink to call:

    https://www.reddit.com{permalink}.json

Example:

    https://www.reddit.com/r/SEO/comments/abc123/sample_post/.json

# Implement Detailed Reddit Post Storage

Create and properly implement `reddit_posts` table.

Store the following fields:

    title
    selftext
    created_utc
    ups
    downs
    total_awards_received
    author
    permalink
    thumbnail
    url

Also store:

    search_id
    result_id
    reddit_post_id
    raw_json

# Database Requirements

Use:

-   Proper column types
-   Foreign key constraints
-   Database indexes
-   Unique constraints
-   Optimized schema design

# Database Relationships

## searches table

    searches
       -> hasMany(results)

    searches
       -> hasMany(reddit_posts)

## results table

    results
       -> belongsTo(searches)

    results
       -> hasOne(reddit_posts)

## reddit_posts table

    reddit_posts
       -> belongsTo(searches)

    reddit_posts
       -> belongsTo(results)

# Important Instruction

Review ALL existing implemented code and:

-   Correct issues where needed
-   Improve architecture
-   Refactor poor logic
-   Preserve working functionality
-   Add missing fields
-   Add missing migrations only where needed
-   Avoid recreating existing tables unnecessarily
-   Refactor duplicate logic into reusable services/helpers
-   Improve readability and maintainability

# Queue-Based Processing

Implement Reddit detail fetching using Laravel Queue Jobs.

Expected workflow:

    Form Submit
       ->
    Store search keyword
       ->
    Fetch Reddit search results
       ->
    Store results
       ->
    Dispatch Queue Jobs
       ->
    Fetch detail Reddit API
       ->
    Store reddit_posts data

# Queue Requirements

Queue implementation must be production-ready.

Use:

-   Queue Jobs
-   Retry handling
-   Failed jobs handling
-   Chunk processing
-   Batch processing
-   Memory-efficient processing

# Technical Requirements

Use:

-   Laravel 12
-   Service Classes
-   Queue Jobs
-   Laravel HTTP Client
-   Dependency Injection
-   SOLID principles
-   Repository/Service pattern if necessary
-   Reusable helper methods
-   Proper separation of concerns

# HTTP Request Requirements

Use Laravel HTTP Client only:

    Http::timeout()->get()

Do NOT use cURL.

Implement:

-   Timeout handling
-   Retry handling
-   API exception handling
-   Response validation
-   Graceful failures

# Existing Code Review Requirements

Review and improve:

-   Controllers
-   Models
-   Relationships
-   Migrations
-   API integrations
-   Validation
-   Insert/update logic
-   Queue processing
-   Duplicate prevention
-   Error handling
-   Logging
-   Naming conventions
-   Code structure
-   Reusability

# Duplicate Prevention

Prevent duplicate Reddit posts using:

    permalink

OR

    reddit_post_id

Use:

-   Unique indexes
-   Validation logic
-   updateOrCreate() where appropriate

# Error Handling

Implement proper:

-   try/catch blocks
-   Timeout handling
-   Retry logic
-   Failed job handling
-   Logging
-   Null response handling
-   API error handling
-   Graceful failures

Use Laravel logging properly.

# Performance Optimization

Implement:

-   Chunking
-   Queue workers
-   Async processing
-   Optimized database queries
-   Proper indexing
-   Eager loading where needed
-   Batch inserts where appropriate
-   Memory-efficient processing

# Store Raw JSON Response

Store complete API response inside:

    raw_json

Use JSON column type where possible.

# Deliverables Required

Provide complete corrected implementation including:

1.  Existing architecture review
2.  Corrections in existing code
3.  Missing migrations
4.  Updated database relationships
5.  Updated models
6.  Queue Jobs
7.  Service Classes
8.  Reddit detail API integration
9.  JSON parsing logic
10. Insert/update logic
11. Error handling
12. Retry handling
13. Logging
14. Validation
15. Optimized database structure
16. Queue configuration
17. Performance optimization
18. Production-ready Laravel 12 implementation
19. Clean reusable architecture
20. Refactored maintainable code structure

# Important Goal

Do NOT generate only generic example code.

Instead:

-   Analyze existing implementation assumptions
-   Improve existing architecture
-   Extend existing functionality properly
-   Write scalable and maintainable Laravel 12 code
-   Implement enterprise-level architecture
-   Keep all relationships connected properly
-   Ensure everything runs automatically after form submission
-   Write reusable modular code
-   Keep controllers thin
-   Move business logic into services/jobs
-   Follow Laravel coding standards throughout the implementation
