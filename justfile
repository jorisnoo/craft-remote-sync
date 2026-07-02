# List available recipes
default:
    @just --list

# Install dependencies
install:
    composer install

# Update dependencies
update:
    composer update

# Run tests
test:
    vendor/bin/pest

# Run tests with coverage
test-coverage:
    vendor/bin/pest --coverage

# Run all checks
check: test
