class CoreTemplateEngine {
  private templates = new Map();
  private securityRules = {
    allowedTags: ['div', 'p', 'h1', 'h2', 'h3', 'img', 'span'],
    allowedAttributes: ['class', 'id', 'src', 'alt']
  };

  registerTemplate(name: string, template: string): void {
    this.templates.set(name, template);
  }

  render(name: string, data: any): string {
    const template = this.templates.get(name);
    if (!template) throw new Error('Template not found');
    return this.compile(template, this.validateData(data));
  }

  private validateData(data: any): any {
    if (!data || typeof data !== 'object') throw new Error('Invalid data');
    return data;
  }

  private compile(template: string, data: any): string {
    return template.replace(/\{\{(.+?)\}\}/g, (_, key) => data[key] || '');
  }
}

export default CoreTemplateEngine;
