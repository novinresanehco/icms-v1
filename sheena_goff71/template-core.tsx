import React from 'react';
import { Alert } from '@/components/ui/alert';

// Content Display System
const ContentDisplay = ({ content, type = 'default' }) => {
  const renderContent = () => {
    switch (type) {
      case 'article':
        return (
          <article className="prose max-w-none">
            <h1 className="text-2xl font-bold">{content.title}</h1>
            <div className="mt-4">{content.body}</div>
          </article>
        );
      case 'page':
        return (
          <div className="space-y-6">
            <header className="border-b pb-4">
              <h1 className="text-3xl font-bold">{content.title}</h1>
            </header>
            <main>{content.body}</main>
          </div>
        );
      default:
        return <div className="prose">{content.body}</div>;
    }
  };

  return (
    <div className="p-6 bg-white rounded-lg shadow">
      {renderContent()}
    </div>
  );
};

// Media Gallery Integration
const MediaGallery = ({ items = [], layout = 'grid' }) => {
  const gridLayouts = {
    grid: 'grid-cols-3',
    masonry: 'columns-3',
    list: 'grid-cols-1'
  };

  return (
    <div className={`grid gap-4 ${gridLayouts[layout]}`}>
      {items.map((item, index) => (
        <div key={index} className="aspect-square bg-gray-100 rounded-lg overflow-hidden">
          <img
            src="/api/placeholder/400/400"
            alt={item.alt}
            className="w-full h-full object-cover"
          />
        </div>
      ))}
    </div>
  );
};

// Template Rendering Engine
const TemplateEngine = ({ template, content }) => {
  const [error, setError] = useState(null);

  const renderSection = (section) => {
    try {
      switch (section.type) {
        case 'content':
          return <ContentDisplay content={section.content} type={section.contentType} />;
        case 'media':
          return <MediaGallery items={section.items} layout={section.layout} />;
        case 'container':
          return (
            <div className={`grid ${section.columns ? `md:grid-cols-${section.columns}` : ''} gap-4`}>
              {section.children?.map((child, index) => (
                <div key={index}>{renderSection(child)}</div>
              ))}
            </div>
          );
        default:
          throw new Error(`Unknown section type: ${section.type}`);
      }
    } catch (e) {
      setError(e.message);
      return <Alert variant="destructive">{e.message}</Alert>;
    }
  };

  return (
    <div className="template-root space-y-6">
      {error ? (
        <Alert variant="destructive">{error}</Alert>
      ) : (
        template.sections?.map((section, index) => (
          <div key={index}>{renderSection(section)}</div>
        ))
      )}
    </div>
  );
};

export { ContentDisplay, MediaGallery, TemplateEngine };
