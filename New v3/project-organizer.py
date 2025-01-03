import os
import shutil
import json
import logging
from typing import Dict, List, Any
from dataclasses import dataclass
from datetime import datetime

@dataclass
class FileInfo:
    path: str
    category: str
    priority: str
    status: str
    dependencies: List[str]
    last_modified: datetime
    needs_update: bool

class ProjectOrganizer:
    def __init__(self, root_path: str, config_path: str):
        self.root_path = root_path
        self.config_path = config_path
        self.file_registry: Dict[str, FileInfo] = {}
        
        # Setup logging
        logging.basicConfig(
            filename='project_organization.log',
            level=logging.INFO,
            format='%(asctime)s - %(levelname)s - %(message)s'
        )
        self.logger = logging.getLogger(__name__)

        # Load configuration
        self.config = self.load_configuration()
        
        # Define core directories
        self.core_directories = {
            'security': 'Core/Security',
            'content': 'Core/Content',
            'template': 'Core/Template',
            'infrastructure': 'Infrastructure',
            'tests': 'Tests',
            'docs': 'Documentation'
        }

    def load_configuration(self) -> Dict[str, Any]:
        """Load project configuration from JSON file"""
        try:
            with open(self.config_path, 'r', encoding='utf-8') as f:
                return json.load(f)
        except Exception as e:
            self.logger.error(f"Failed to load configuration: {e}")
            raise

    def analyze_files(self) -> None:
        """Analyze all project files and categorize them"""
        for root, _, files in os.walk(self.root_path):
            for file in files:
                if file.endswith('.php'):
                    file_path = os.path.join(root, file)
                    self.analyze_single_file(file_path)

    def analyze_single_file(self, file_path: str) -> None:
        """Analyze a single file and categorize it"""
        try:
            with open(file_path, 'r', encoding='utf-8') as f:
                content = f.read()

            # Determine file category and priority
            category = self.determine_category(content)
            priority = self.determine_priority(content)
            status = self.determine_status(content)
            dependencies = self.find_dependencies(content)

            file_info = FileInfo(
                path=file_path,
                category=category,
                priority=priority,
                status=status,
                dependencies=dependencies,
                last_modified=datetime.fromtimestamp(os.path.getmtime(file_path)),
                needs_update=self.check_if_needs_update(content)
            )

            self.file_registry[file_path] = file_info
            self.logger.info(f"Analyzed file: {file_path} - Category: {category} - Priority: {priority}")

        except Exception as e:
            self.logger.error(f"Error analyzing file {file_path}: {e}")

    def determine_category(self, content: str) -> str:
        """Determine the category of a file based on its content"""
        categories = {
            'security': ['SecurityManager', 'Authentication', 'Authorization'],
            'content': ['ContentManager', 'MediaHandler', 'CategoryManager'],
            'template': ['TemplateEngine', 'CacheManager'],
            'infrastructure': ['Database', 'Cache', 'Logger']
        }

        for category, keywords in categories.items():
            if any(keyword in content for keyword in keywords):
                return category
        return 'misc'

    def determine_priority(self, content: str) -> str:
        """Determine the priority of a file based on its content and dependencies"""
        if 'CRITICAL' in content or 'HIGH_PRIORITY' in content:
            return 'high'
        if 'IMPORTANT' in content or 'MEDIUM_PRIORITY' in content:
            return 'medium'
        return 'low'

    def determine_status(self, content: str) -> str:
        """Determine the status of a file"""
        if 'NEEDS_UPDATE' in content:
            return 'needs_update'
        if 'USABLE' in content:
            return 'usable'
        if 'NEEDS_MERGE' in content:
            return 'needs_merge'
        return 'unknown'

    def find_dependencies(self, content: str) -> List[str]:
        """Find dependencies of a file by analyzing use/import statements"""
        dependencies = []
        for line in content.split('\n'):
            if 'use' in line or 'require' in line or 'include' in line:
                dep = line.split()[-1].strip(';')
                dependencies.append(dep)
        return dependencies

    def check_if_needs_update(self, content: str) -> bool:
        """Check if a file needs updating based on its content and dependencies"""
        return 'TODO' in content or 'FIXME' in content or 'NEEDS_UPDATE' in content

    def organize_files(self) -> None:
        """Organize files into appropriate directories"""
        for file_path, file_info in self.file_registry.items():
            if file_info.category in self.core_directories:
                target_dir = os.path.join(self.root_path, self.core_directories[file_info.category])
                self.ensure_directory(target_dir)
                self.move_file(file_path, target_dir)

    def ensure_directory(self, directory: str) -> None:
        """Ensure a directory exists, create if it doesn't"""
        if not os.path.exists(directory):
            os.makedirs(directory)
            self.logger.info(f"Created directory: {directory}")

    def move_file(self, source: str, target_dir: str) -> None:
        """Move a file to target directory with logging and error handling"""
        try:
            filename = os.path.basename(source)
            destination = os.path.join(target_dir, filename)
            shutil.move(source, destination)
            self.logger.info(f"Moved {source} to {destination}")
        except Exception as e:
            self.logger.error(f"Error moving file {source}: {e}")

    def generate_report(self) -> Dict[str, Any]:
        """Generate a comprehensive report of the project organization"""
        report = {
            'timestamp': datetime.now().isoformat(),
            'total_files': len(self.file_registry),
            'categories': {},
            'priorities': {
                'high': [],
                'medium': [],
                'low': []
            },
            'status': {
                'needs_update': [],
                'usable': [],
                'needs_merge': []
            },
            'dependencies': {}
        }

        for file_info in self.file_registry.values():
            # Update categories
            if file_info.category not in report['categories']:
                report['categories'][file_info.category] = []
            report['categories'][file_info.category].append(file_info.path)

            # Update priorities
            report['priorities'][file_info.priority].append(file_info.path)

            # Update status
            report['status'][file_info.status].append(file_info.path)

            # Update dependencies
            report['dependencies'][file_info.path] = file_info.dependencies

        return report

    def save_report(self, report: Dict[str, Any], output_path: str) -> None:
        """Save the generated report to a JSON file"""
        try:
            with open(output_path, 'w', encoding='utf-8') as f:
                json.dump(report, f, indent=4)
            self.logger.info(f"Saved report to {output_path}")
        except Exception as e:
            self.logger.error(f"Error saving report: {e}")

def main():
    # Configuration
    ROOT_PATH = "path/to/project/root"
    CONFIG_PATH = "path/to/config.json"
    REPORT_PATH = "project_report.json"

    # Initialize and run organizer
    try:
        organizer = ProjectOrganizer(ROOT_PATH, CONFIG_PATH)
        
        # Analyze files
        organizer.analyze_files()
        
        # Organize files
        organizer.organize_files()
        
        # Generate and save report
        report = organizer.generate_report()
        organizer.save_report(report, REPORT_PATH)
        
        print("Project organization completed successfully")
        print(f"Check {REPORT_PATH} for detailed report")
        
    except Exception as e:
        print(f"Error during project organization: {e}")
        logging.error(f"Critical error during project organization: {e}")
        raise

if __name__ == "__main__":
    main()