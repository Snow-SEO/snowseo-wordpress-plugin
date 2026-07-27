import zipfile
import os

plugin_dir = os.path.dirname(os.path.abspath(__file__))
zip_path = os.path.join(plugin_dir, 'snowseo.zip')

files_to_zip = ['snowseo.php', 'readme.txt', 'uninstall.php', 'LICENSE.txt']
dirs_to_zip = ['build', 'includes']

print(f"Creating zip file at: {zip_path}")

with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as z:
    for f in files_to_zip:
        filepath = os.path.join(plugin_dir, f)
        if os.path.exists(filepath):
            z.write(filepath, os.path.join('snowseo', f))
            print(f"Added file: {f}")
    
    for d in dirs_to_zip:
        dirpath = os.path.join(plugin_dir, d)
        if os.path.exists(dirpath):
            for root, dirs, files in os.walk(dirpath):
                for file in files:
                    full_path = os.path.join(root, file)
                    rel_path = os.path.relpath(full_path, plugin_dir)
                    z.write(full_path, os.path.join('snowseo', rel_path))
            print(f"Added directory: {d}")

print("ZIP ready!")
