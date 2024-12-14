const TemplateEngine = {
  allowedTemplates: new Set(['content', 'media', 'gallery']),
  
  securityRules: {
    allowedTags: ['div', 'p', 'h1', 'h2', 'h3', 'img', 'span'],
    allowedAttributes: ['class', 'id', 'src', 'alt', 'title'],
    maxDepth: 5
  },

  cache: new Map(),

  async render(template, data, context) {
    if (!this.allowedTemplates.has(template)) throw new Error('Invalid template');
    const cacheKey = this.getCacheKey(template, data);
    
    if (this.cache.has(cacheKey)) return this.cache.get(cacheKey);
    
    const validated = await this.validateData(data, context);
    const rendered = await this.compileTemplate(template, validated);
    
    this.cache.set(cacheKey, rendered);
    return rendered;
  },

  validateData(data, context) {
    if (!data || typeof data !== 'object') throw new Error('Invalid data');
    return data;
  },

  compileTemplate(template, data) {
    return `<div>${data.content || ''}</div>`;
  },

  getCacheKey(template, data) {
    return `${template}:${JSON.stringify(data)}`;
  }
};

export default TemplateEngine;
