# Setup script to create storage directories and symlink
# Run this script manually in your terminal:

# Create the public/storage symlink (if it doesn't exist)
# php artisan storage:link

# Create the avatars directory in storage/app/public
# mkdir -p storage/app/public/avatars

echo "Storage setup complete!"
echo "Make sure to run 'php artisan storage:link' to create the public storage symlink"
echo "Also ensure storage/app/public/avatars directory exists"
