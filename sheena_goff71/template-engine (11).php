class TemplateEngine {
  private templates = new Map();

  private securityRules = {
    allowedTags: ['div', 'p', 'h1', 'h2', 'h3', 'img', 'span'],
    allowedAttributes: ['class', 'id', 'src', 'alt', 'title'],
    maxDepth: 5
  };

  registerTemplate(name: string, template: string): void {
    if (!this.validateTemplate(template)) {
      throw new Error('Invalid template');
    }
    this.templates.set(name, template);
  }

  render(name: string, data: any): string {
    const template = this.templates.get(name);
    if (!template) throw new Error('Template not found');
    
    const validatedData = this.validateData(data);
    return this.compile(template, validatedData);
  }

  private validateTemplate(template: string): boolean {
    const depth = this.calculateDepth(template);
    return depth <= this.securityRules.maxDepth;
  }

  private validateData(data: any): any {
    if (!data || typeof data !== 'object') {
      throw new Error('Invalid data');
    }
    return Object.fromEntries(
      Object.entries(data).map(([k, v]) => [k, this.sanitize(v)])
    );
  }

  private compile(template: string, data: any): string {
    return template.replace(
      /\{\{(.+?)\}\}/g,
      (_, key) => data[key] || ''
    );
  }

  private sanitize(value: any): any {
    if (typeof value === 'string') {
      return value.replace(/[<>]/g, '');
    }
    return value;
  }

  private calculateDepth(template: string): number {
    return (template.match(/<[^>]+>/g) || []).length;
  }
}

export default TemplateEngine;
