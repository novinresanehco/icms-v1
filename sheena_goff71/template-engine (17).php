import React, { useCallback, useMemo } from 'react';
import { AlertCircle } from 'lucide-react';
import { Alert } from '@/components/ui/alert';

// Template Registry - Stores available template definitions
const templateRegistry = {
  default: {
    layout: 'single',
    zones: ['main'],
    className: 'max-w-4xl mx-auto'
  },
  twoColumn: {
    layout: 'two-column',
    zones: ['main', 'sidebar'],
    className: 'grid grid-cols-1 md:grid-cols-3 gap-6'
  },
  fullWidth: {
    layout: 'full',
    zones: ['header', 'main', 'footer'],
    className: 'w-full space-y-6'
  }
};

// Content Zone Component
const ContentZone = ({ content, className = '' }) => {
  if (!content) {
    return (
      <Alert>
        <AlertCircle className="h-4 w-4" />
        <div>No content available for this zone</div>
      </Alert>
    );
  }

  return (
    <div className={className}>
      {typeof content === 'string' ? (
        <div dangerouslySetInnerHTML={{ __html: content }} />
      ) : (
        content
      )}
    </div>
  );
};

// Template Renderer Component
const TemplateRenderer = ({ 
  template = 'default',
  content = {},
  media = [],
  className = ''
}) => {
  // Get template definition
  const templateDef = useMemo(() => {
    return templateRegistry[template] || templateRegistry.default;
  }, [template]);

  // Render zones based on template
  const renderZones = useCallback(() => {
    return templateDef.zones.map(zone => (
      <ContentZone
        key={zone}
        content={content[zone]}
        className={`template-zone-${zone}`}
      />
    ));
  }, [templateDef, content]);

  // Media gallery integration
  const renderMedia = useCallback(() => {
    if (!media.length) return null;

    return (
      <div className="mt-8">
        <MediaGallery items={media} />
      </div>
    );
  }, [media]);

  return (
    <div className={`template ${templateDef.className} ${className}`}>
      {renderZones()}
      {renderMedia()}
    </div>
  );
};

// Media Gallery Component
const MediaGallery = ({ items = [] }) => {
  if (!items.length) return null;

  return (
    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
      {items.map((item, index) => (
        <div key={item.id || index} className="relative aspect-square">
          <img
            src={item.url || '/api/placeholder/400/320'}
            alt={item.alt || ''}
            className="w-full h-full object-cover rounded-lg"
          />
        </div>
      ))}
    </div>
  );
};

export default function Template({ 
  type = 'default',
  content = {},
  media = [],
  className = ''
}) {
  return (
    <TemplateRenderer
      template={type}
      content={content}
      media={media}
      className={className}
    />
  );
}
